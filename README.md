# Tumblr TaggerBot

NOTE: This doesn't actually update/edit posts yet, I've just got it working in a "dry run" mode.

This is a PHP script that runs through a blog's posts, or a specific post, and uses some LLMs/computer-vision jawns to classify the post content into a set of tags.

For example, using `llava-v1.5-7b` to describe the image(s) and `mistral-small-24b-instruct-2501` to determine tags, [this post](https://cyle.tumblr.com/post/774208078163869696) generates the tags `friday challenge, odd expression, man in brown jacket, surprised reaction, by the car`. Hilarious!

Usually it's pretty good, but sometimes it's hilariously wrong. I tried using `deepseek-r1-distill-llama-8b` but it was actually much worse than `mistral-small-24b-instruct-2501`. Deepseek seemed to be trying way too hard.

I've been playing with this after having installed [LM Studio](https://lmstudio.ai/) on my PC, downloading a bunch of models, running its local network server service, and toying with this script. I highly encourage playing with the prompts, little changes can really dramatically adjust the output tags. It still struggles not to fall back to #SingleWordTags or old-school-hyphenated-tags, sometimes.

This was built with PHP 8.4.3 in mind, but I bet it would run with any 8.x version of PHP,  there's not much fancy here.

## Installation and usage

1. Set up some server to host your models -- as specified, I used [LM Studio](https://lmstudio.ai/) and its [local server mode](https://lmstudio.ai/docs/api) (you can find it in LM Studio's settings). Copy the URL it gives you for later.
2. Download the relevant models in LM Studio. I used `llava-v1.5-7b` and `mistral-small-24b-instruct-2501` here, but you can change them in the `MODELS_TO_USE` constant.
3. Make sure you have PHP 8.x (8.4.3 if you want to match me).
4. You'll need to [register an app](https://www.tumblr.com/oauth/apps) on Tumblr to get a Tumblr API key. 
5. Rename/copy `config.sample.php` to `config.php` and edit the file with the relevant info. (Decide which blog you want to use this on! It must be yours, obviously.)

To run it against the blog's posts one at a time in reverse-chronological order:

```
$ cd /path/to/this/repo
$ php bot.php
```

... or if you want to use it against just one post:

```
$ cd /path/to/this/repo
$ php bot.php "https://blog-name.tumblr.com/post/1234"
```

(Note that "https://www.tumblr.com/blog-name/1234" URLs also work.)
