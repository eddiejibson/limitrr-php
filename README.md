# Do not use, this is an in progress project.

<div align="center">
<a href="https://github.com/eddiejibson/chae-limitrr"><img alt="chae" src="https://cdn.oxro.io/chae/img/limitrr-php.png" width="432.8" height="114.2"></a>
<br>
<br>
<!-- <img src="https://circleci.com/gh/eddiejibson/limitrr-php.svg?style=svg"></img> -->
<img src="https://www.codefactor.io/repository/github/eddiejibson/limitrr-php/badge">
<a href="https://paypal.me/eddiejibson/5"><img src="https://img.shields.io/badge/donate-PayPal-brightgreen.svg"></a>
<!-- <img src="https://requires.io/github/eddiejibson/chae-limitrr/requirements.svg?branch=master"> -->
<!-- <img src="https://img.shields.io/npm/dw/limitrr.svg"> -->

Light rate limting within PHP using Redis.
</div>

Limitrr PHP is very heavily inspired by my other library, Limitrr which was created for NodeJS. Check it out [here](http://github.com/eddiejibson/chae-limitrr)

Limitrr PHP allows users to easily integrate rate limiting within their application. Unlike other similar packages, this utility allows the user to limit not only by the number of requests but also the number of completed actions (e.g allowing a certain amount of accounts to be successfully created within a timespan) and have such restricted with custom options. As well as this, custom discriminators are possible - you no longer have to limit by just the user's IP.

This library also provides a middleware function for easily ratelimting whatever various routes you may have within a SlimPHP project.

If you appreciate this project, please 🌟 it on GitHub.

**Pull Requests are welcomed**

## Installation

Then, after you have installed the Redis driver, you can install the limitrr-php libary via executing the following commandline in your terminal:

```bash
composer require eddiejibson/limitrr-php
```

