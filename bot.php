<?php declare(strict_types=1);

require_once 'config.php';
require_once 'vendor/autoload.php';

/**
 * A bot script that runs through a blog's posts on Tumblr and asks some robots to generate tags for them.
 * Because I am way too lazy to tag my own posts, maybe the robots can do it for me.
 *
 * By default, it runs through the given blog, but you can supply a specific URL to get just one post's tags.
 * Command line options:
 *   --dry-run=0 to disable dry run mode and actually edit posts.
 *   --force to ignore the special indicator tag and force-reclassify post tags.
 *   --post="url here" to classify tags for just one specific post, and then stop.
 */
class TumblrTaggerbot
{
    /**
     * Models to use for various functions. "image" is to classify images, "tags" is to get tags from the post content.
     */
    protected const MODELS_TO_USE = [
        'image' => 'llava-v1.5-7b', // does an OK job
        // 'tags' => 'deepseek-r1-distill-llama-8b', // i thought this was good, but it's too wordy
        'tags' => 'mistral-small-24b-instruct-2501', // much more concise/punchy
    ];

    /**
     * How many posts to parse in one run.
     * @todo turn this into a runtime param
     */
    protected const POSTS_TO_PARSE = 50;

    /**
     * This tag will be used to indicate that the post has already been processed.
     */
    protected const SPECIAL_INDICATOR_TAG = 'ai generated tags';

    /** @var \Tumblr\API\Client Our API client to update posts... */
    protected \Tumblr\API\Client $tumblr_client;

    /** @var bool Whether we should actually update posts or not. */
    protected bool $dry_run = false;

    /** @var bool Whether we should ignore the SPECIAL_INDICATOR_TAG and reprocess posts anyway. */
    protected bool $force_update = false;

    /** @var string|null A specific post to classify and tag, rather than the latest posts on the blog. */
    protected ?string $specific_post = null;

    /**
     * Construct a new robot.
     * @param bool $dry_run Whether to be in DRY RUN mode or not; dry run means no actual updates.
     * @param bool $force_update Whether to ignore the SPECIAL_INDICATOR_TAG and force-update post tags.
     * @param string|null $specific_post A specific post to look at, rather than latest posts.
     */
    public function __construct(bool $dry_run = true, bool $force_update = false, ?string $specific_post = null)
    {
        $this->dry_run = $dry_run;
        $this->force_update = $force_update;
        $this->specific_post = $specific_post;
    }

    /**
     * Run the script!
     * @return void
     */
    public function run(): void
    {
        $this->log('Tumblr TaggerBot, at your service!');
        $this->log('Dry run mode? ' . ($this->dry_run ? 'Yes, no updates will be made.' : 'No, EDITING MODE!'));
        $this->log('Forcing reprocessing of posts? ' . ($this->force_update ? 'Yep.' : 'Nope.'));
        $this->log('');
        $this->log(sprintf('Using "%s" for image recognition.', self::MODELS_TO_USE['image']));
        $this->log(sprintf('Using "%s" for determining tags.', self::MODELS_TO_USE['tags']));
        $this->log('Verifying the robot models are available...');

        if (!$this->verifyModelsExist()) {
            $this->log('Needed models are not available, aborting!');
            exit(1);
        }

        $this->tumblr_client = new \Tumblr\API\Client(
            TUMBLR_OAUTH_KEY,
            TUMBLR_OAUTH_SECRET,
            TUMBLR_OAUTH_TOKEN,
            TUMBLR_OAUTH_TOKEN_SECRET
        );

        $need_sleep = false;
        $url = $this->specific_post ?? '';
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $this->log('Gonna try to tag ' . $url);
            $host = parse_url($url, PHP_URL_HOST);
            $path = parse_url($url, PHP_URL_PATH);
            $path_pieces = explode('/', $path);
            if ($host === 'www.tumblr.com') {
                $blog_url = $path_pieces[1] . '.tumblr.com';
                $post_id = (int) $path_pieces[2];
            } else {
                $blog_url = $host;
                $post_id = (int) $path_pieces[2];
            }
            $this->log('Fetching post ' . $post_id . ' from blog ' . $blog_url);
            $posts = $this->getPost($post_id, $blog_url);
        } else {
            $this->log(sprintf('Getting the latest %d posts from %s to tag...', self::POSTS_TO_PARSE, BLOG_URL));
            $posts = $this->getPosts();
            $need_sleep = true;
        }

        foreach ($posts as $post_id => $post) {
            $this->log('Generating tags for post: ' . $post['url']);
            $new_tags = $this->generateTagsForPost($post);
            if (empty($new_tags)) {
                $this->log('No tags given, moving on...');
                $this->log('');
                continue;
            }

            if ($this->dry_run) {
                $this->log('We would update the post here, but we\'re in DRY RUN MODE! Moving on...');
                $this->log('');
                continue;
            }

            $new_tags[] = self::SPECIAL_INDICATOR_TAG; // so we don't reprocess this
            $this->log('Updating tags on post...');
            $this->updateTags($post_id, $new_tags);
            $this->log('Updated post!');
            if ($need_sleep) {
                $this->log('Waiting a second before doing the next one...');
                $this->log('');
                sleep(1);
            }
        }

        $this->log('All done!!! Wooowwweeeeeee! Robots loved being used!');
        exit(0);
    }

    /**
     * Print some crap out to STDOUT for human reading. Or maybe AI reading, who knows.
     * @param string $message The message to print out.
     * @return void
     */
    protected function log(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    /**
     * Update da tags on da post
     * @param int $post_id The post
     * @param array $new_tags The new tags
     * @return void
     */
    protected function updateTags(int $post_id, array $new_tags): void
    {
        // the old API edit post endpoint expects tags to be a string, not an array, lol
        $this->tumblr_client->editPost(BLOG_URL, $post_id, ['tags' => implode(',', $new_tags)]);
    }

    /**
     * Get one specific post
     * @param int $post_id The specific post ID
     * @param string $blog_url The blog that owns the post
     * @return array It should have just one element hopefully
     */
    protected function getPost(int $post_id, string $blog_url): array
    {
        $tumblr_posts_response = $this->doAPICurl(TUMBLR_API_BASE_URL, sprintf('/blog/%s/posts?api_key=%s&npf=true&id=%s', $blog_url, TUMBLR_API_KEY, $post_id));
        // $this->log('Response from Tumblr: ' . var_export($tumblr_posts_response, true));

        $posts = [];
        $raw_post_objects = $tumblr_posts_response['response']['posts'];
        foreach ($raw_post_objects as $raw_post_object) {
            if (!$this->force_update && in_array(self::SPECIAL_INDICATOR_TAG, $raw_post_object['tags'], true)) {
                $this->log($raw_post_object['id_string'] . ' already has AI-generated tags, skipping.');
                continue;
            }

            $posts[$raw_post_object['id_string']] = [
                'id' => $raw_post_object['id_string'],
                'url' => $raw_post_object['post_url'],
                'content' => $raw_post_object['content'],
                'trail' => $raw_post_object['trail'],
                'old_tags' => $raw_post_object['tags'],
            ];
        }

        return $posts;
    }

    /**
     * Get some posts from a blog, up to
     * @param string|null $specific_uri A specific posts-fetching URI to hit.
     * @param array|null $posts An ongoing list of posts we've fetched.
     * @return array Of post objects to parse/classify.
     */
    protected function getPosts(?string $specific_uri = null, ?array $posts = null): array
    {
        $specific_uri ??= sprintf('/blog/%s/posts?api_key=%s&npf=true&before=%s', BLOG_URL, TUMBLR_API_KEY, time());
        $tumblr_posts_response = $this->doAPICurl(TUMBLR_API_BASE_URL, $specific_uri);
        // $this->log('Response from Tumblr: ' . var_export($tumblr_posts_response, true));

        $posts ??= [];
        $raw_post_objects = $tumblr_posts_response['response']['posts'];
        foreach ($raw_post_objects as $raw_post_object) {
            if (!$this->force_update && in_array(self::SPECIAL_INDICATOR_TAG, $raw_post_object['tags'], true)) {
                $this->log($raw_post_object['id_string'] . ' already has AI-generated tags, skipping.');
                continue;
            }

            // bail out early if we've done enough here
            if (count($posts) === self::POSTS_TO_PARSE) {
                return $posts;
            }

            // @todo somehow skip pinned posts as well? or do we not even get them?

            $posts[$raw_post_object['id_string']] = [
                'id' => $raw_post_object['id_string'],
                'url' => $raw_post_object['post_url'],
                'content' => $raw_post_object['content'],
                'trail' => $raw_post_object['trail'],
                'old_tags' => $raw_post_object['tags'],
            ];
        }

        $next_page_uri = $tumblr_posts_response['response']['_links']['next']['href'] ?? null;
        if ($next_page_uri !== null) {
            $next_page_uri = str_replace('/v2', '', $next_page_uri);
            $next_page_uri .= '&api_key=' . TUMBLR_API_KEY;
            $this->log('Need MORE POSTS!!! Fetching the next page after a second...');
            sleep(1);
            return $this->getPosts($next_page_uri, $posts);
        }

        return $posts;
    }

    /**
     * Generate tags from an LLM based on the given post content
     * @param array $post_content The post content, @see getPosts() for the expected format
     * @return string[]
     */
    protected function generateTagsForPost(array $post_content): array
    {
        $text_content = '';
        $image_urls = [];

        $all_post_content = [];
        foreach ($post_content['trail'] as $trail_item) {
            foreach ($trail_item['content'] as $content_block) {
                $all_post_content[] = $content_block;
            }
        }

        $all_post_content = array_merge($all_post_content, $post_content['content']);

        foreach ($all_post_content as $content_block) {
            if ($content_block['type'] === 'image') {
                foreach ($content_block['media'] as $media) {
                    // pick whichever one of these comes first
                    if ($media['width'] === 640 || $media['width'] === 540 || $media['width'] === 500 || $media['width'] === 400 || $media['width'] === 220 || $media['width'] === 100) {
                        $image_urls[] = $media['url'];
                        // actually classify the image right here so it's inline with everything else...
                        $image_description = $this->getDescriptionOfImage($media['url']);
                        $text_content .= 'Image description: ' . $image_description . "\n\n";
                        break;
                    }
                }
            }

            if ($content_block['type'] === 'text') {
                $text_content .= $content_block['text'] . "\n\n";
            }
        }

        $text_content = trim($text_content);

        if ($text_content === '' && $image_urls === []) {
            $this->log('Found no content to classify! Weird.');
            return [];
        }

        $this->log('Post content given: ' . $text_content);
        $this->log('Based on image urls: ' . implode(', ', $image_urls));

        $new_tags = [];

        // set up our LLM prompt
        $payload = [
            'model' => self::MODELS_TO_USE['tags'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an assistant helping a person on Tumblr generate tags to describe and catalog their post. ' .
                        'You need to come up with between 3 and 10 phrases or keywords to describe the post content. ' .
                        'The post content includes text used in the post and descriptions of images uploaded in the post. ' .
                        'You should be very confident in the phrases and keywords you choose to describe the content, discarding keywords and phrases that seem incorrect, and avoid duplicate keywords and duplicate concepts. ' .
                        'The keywords you choose should be descriptive and accurate, but also fun and useful, with the goal of helping others find and understand the post content. ' .
                        'The keywords should be in English, and you can use spaces to separate words instead of trying to create a single tag that concatenates words together. ' .
                        'Your goal is to generate a single line of text with a comma-separated list of your chosen keywords and phrases. Please respond with ONLY that single line of text with a comma-separated list of your chosen keywords and phrases.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Post content: ' . $text_content,
                ],
            ],
        ];

        $this->log('Asking the robot what they think the tags should be...');
        $response = $this->doAPICurl(MODEL_API_BASE_URL, '/v1/chat/completions', $payload);
        // $this->log('Response: ' . var_export($response, true));
        // deepseek responses include a <think> block to describe its reasoning, we don't need that
        $final_response = trim(preg_replace('/<think>[\S\s]+<\/think>/i', '', trim($response['choices'][0]['message']['content'] ?? '')));
        // $this->log('Response without thinks: ' . $final_response);

        $tags = explode(',', $final_response);
        foreach ($tags as $tag) {
            $new_tags[] = mb_strtolower(trim($tag), 'UTF-8');
        }

        // make sure the tags don't have newlines
        $tags_string = implode(', ', $new_tags);
        $this->log('Got new tags: ' . $tags_string);

        if (str_contains($tags_string, "\n")) {
            // sometimes the response will
            $this->log('Tags contained a newline, probably a bad response from the robot, returning nothing...');
            return [];
        }

        return $new_tags;
    }

    /**
     * Verify the models we need to use exist on the server we're using.
     * @return bool
     */
    protected function verifyModelsExist(): bool
    {
        $models_response = $this->doAPICurl(MODEL_API_BASE_URL, '/v1/models');
        if (!array_key_exists('data', $models_response) || !is_array($models_response['data'])) {
            return false;
        }

        $models = [];
        foreach ($models_response['data'] as $model) {
            $model_id = $model['id'] ?? null;
            if ($model_id === null) {
                continue;
            }
            $models[] = $model_id;
        }

        $models_not_found = array_diff(array_values(self::MODELS_TO_USE), $models);
        if ($models_not_found === []) {
            return true;
        }

        $this->log('Models not found: ' . implode(', ', $models_not_found));
        return false;
    }

    /**
     * Do an API-style curl that expects JSON.
     * @param string $base_url The base URL.
     * @param string $uri The specific URI.
     * @param array $payload The payload, if there's any.
     * @return array The decoded response.
     */
    protected function doAPICurl(string $base_url, string $uri, array $payload = []): array
    {
        $start = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $base_url . $uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Cylesoft/Tumblr-TaggerBot');

        if ($payload !== []) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $stuff = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->log(sprintf('(that took %s seconds)', number_format(microtime(true) - $start, 3)));

        if ($http_code !== 200) {
            $this->log('Got a ' . $http_code . ' response with: ' . $stuff);
            return [];
        }

        $decoded = json_decode($stuff, true);
        if ($decoded === null) {
            $this->log('Got an invalid JSON response: ' . $stuff);
            return [];
        }

        return $decoded;
    }

    /**
     * Do a simple image grab of the given URL.
     * @param string $url The URL to download.
     * @return string The raw image data.
     */
    protected function doImageCurl(string $url): string
    {
        $url = str_replace(['.gifv', '.pnj'], ['.gif', '.png'], $url);
        $start = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Cylesoft/Tumblr-TaggerBot');
        $stuff = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->log(sprintf('(that took %s seconds)', number_format(microtime(true) - $start, 3)));

        if ($http_code !== 200) {
            $this->log('Got a ' . $http_code . ' response with: ' . $stuff);
            return '';
        }

        return $stuff;
    }

    /**
     * Use an image classifier to get a description of the given image.
     * @param string $image_url The URL of the image to download and classify.
     * @return string The description.
     */
    protected function getDescriptionOfImage(string $image_url): string
    {
        $this->log('Downloading image to describe...');
        $image_data = $this->doImageCurl($image_url);
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are an AI assistant that analyzes images and describes what they contain.',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => "Provide a brief description of this image."],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($image_data)]],
                ],
            ],
        ];

        $payload = [
            'model' => self::MODELS_TO_USE['image'],
            'messages' => $messages,
        ];

        // $this->log('Image classification payload: ' . var_export($payload, true));
        $this->log('Getting image description...');
        $response = $this->doAPICurl(MODEL_API_BASE_URL, '/v1/chat/completions', $payload);
        // $this->log('Image description received: ' . var_export($response, true));
        return str_replace("\n", ' ', trim($response['choices'][0]['message']['content'] ?? ''));
    }

    /**
     * Process command line options, if available, into the expected values.
     * @return array
     */
    public static function processOptions(): array
    {
        $long_options = ["dry-run:", "force", "post:"];
        $options = getopt('', $long_options);
        if (isset($options['dry-run']) && $options['dry-run'] === '0') {
            $dry_run_mode = false;
        } else {
            $dry_run_mode = true;
        }
        return [
            'dry-run' => $dry_run_mode,
            'force' => isset($options['force']),
            'post' => $options['post'] ?? null,
        ];
    }
}

// run the bot!
$options = TumblrTaggerbot::processOptions();
$bot = new TumblrTaggerbot($options['dry-run'], $options['force'], $options['post']);
$bot->run();