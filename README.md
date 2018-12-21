<div align="center">
<a href="https://github.com/eddiejibson/chae-limitrr"><img alt="chae" src="https://cdn.oxro.io/chae/img/limitrr-php.png" width="432.8" height="114.2"></a>
<br>
<br>
<!-- <img src="https://circleci.com/gh/eddiejibson/limitrr-php.svg?style=svg"></img> -->
<img src="https://www.codefactor.io/repository/github/eddiejibson/limitrr-php/badge">
<a href="https://paypal.me/eddiejibson/5"><img src="https://img.shields.io/badge/donate-PayPal-brightgreen.svg"></a>
<!-- <img src="https://requires.io/github/eddiejibson/chae-limitrr/requirements.svg?branch=master"> -->
<img src="https://img.shields.io/packagist/dt/eddiejibson/limitrr-php.svg">

Light rate limting within PHP using Redis.
</div>

Limitrr PHP is very heavily inspired by my other library, Limitrr which was created for NodeJS. Check it out [here](http://github.com/eddiejibson/chae-limitrr)

Limitrr PHP allows users to easily integrate rate limiting within their application. Unlike other similar packages, this utility allows the user to limit not only by the number of requests but also the number of completed actions (e.g allowing a certain amount of accounts to be successfully created within a timespan) and have such restricted with custom options. As well as this, custom discriminators are possible - you no longer have to limit by just the user's IP.

This library also provides a middleware function for easily ratelimting whatever various routes you may have within a SlimPHP project.

If you appreciate this project, please 🌟 it on GitHub.

**Pull Requests are welcomed**

## Installation

You can install the limitrr-php libary via executing the following commandline in your terminal (assuming you have composer [installed](https://getcomposer.org/download/))

```bash
composer require eddiejibson/limitrr-php
```

## Quick Guide

### Basic Usage

```php
require "/vendor/autoload.php"; //Require composer's autoload

$options = [
    //Redis keystore information
    "redis" => [
        "host" => "666.chae.sh",
        "port" => 6379,
        "password" => "supersecret",
    ],
    "routes" => [
        "default" => [
            "requestsPerExpiry" => 5,
        ],
    ],

];

//Initialize the Limitrr class and pass the options defined above into it
//Note that the options are not required.
$limitrr = new \eddiejibson\limitrr\Limitrr($options);

//Various examples like this can be found further into the documentation,
//for each function.
$result = $limitrr->get(["discriminator" => $ip]);
echo $result["requests"] + " Requests";
echo $result["completed"] + " Completed";



//Usage within SlimPHP
$app = new Slim\App();

//Use the Limitrr SlimPHP middleware function, if you wish:
$app->add(new \eddiejibson\limitrr\RateLimitMiddleware($limitrr)); //Make sure to pass in the main Limitrr
//instance we defined above into the middleware function. This is mandatory.

//You can also add the get IP middleware function, it will append the user's real IP
//(behind Cloudflare or not) to the request.
$app->add(new \eddiejibson\limitrr\getIpMiddleware());

//Example usage within a route
$app->get("/hello/{name}", function ($request, $response, $args) {
    $name = $args["name"];
    $ip = $request->getAttribute('realip'); //Get the IP that was defined within Limitrr's get IP middleware function
    return $response->getBody()->write("Hello, ${name}. Your IP is ${ip}.");
});

//You do not have to app the middleware function to every single route, globally.
//You can do it indivually, too - along with passing options into such. Like so:
$app->get("/createUser/{name}", function ($request, $response, $args) {
    //Non intensive actions like simple verification will have a different limit to intensive ones.
    //and will only be measured in terms of each request via the middleware.
    //No further action is required.
    if (strlen($args["name"]) < 5) {
        //Dummy function creating user
        $res = $someRandomClass->registerUser();
        if ($res) {
            //Intensive actions like actually registering a user should have a
            //different limit to normal requests, hence the completedActionsPerExpiry option.
            //and should only be added to once this task has been completed fully
            //In this example, we will be limiting the amount of completed actions a certain IP can make.
            //Anything can be passed in here, however. For example, a email address or user ID.
            //$request->getAttribute('realip') was determined by calling the middleware earlier - getIpMiddleware()
            $limitrr->complete(["discriminator"] => $ip);
        }
    }
})->add(new \eddiejibson\limitrr\RateLimitMiddleware($limitrr, ["route"=>"createUser"]));
//You can also pass the route name within the limitrr middleware function

$app->run();
```

### Get value of certain key

#### limitrr->get()

**Returns:** Array or Integer

```php
$limitrr->get([
    "discriminator" => $discriminator, //Required
    "route" => $route, //Not required, default is assumed
    "type" => $type //Not required
]);
```

##### ->get() Parameters

***Must be passed into the function via an array***

- **discriminator:** **Required** Where discriminator is the thing being limited (e.g x amount of completed actions per discriminator)
- **route**: *String* What route should the values be retrieved from? If this is not set, it will get the counts from the `default` route
- **type**: *String* Instead of retrieving both values, you can specify either `requests` or `completed` in this key and only that will be returned as an integer.

##### ->get() Examples

```php
$limitrr->get([
    "discriminator" => $discriminator,
    "type" => $type,
    "route" => $route
]); //Besides discriminator, all parameters are optional.
//If type is not passed into the function, it will
//return both the amount of requests and completed actions

//Where discriminator is the thing being limited
//e.g x amount of completed actions/requests per discriminator
$limitrr->get(["discriminator" => $discriminator]);

//This tends to be the user's IP.
$limitrr->get(["discriminator" => $ip]);
//This will return both the amount of requests and completed actions stored under the
//discriminator provided in an object. You can handle like this:
$result = $limitrr->get(["discriminator" => $ip]);
echo $result["requests"] + " Requests";
echo $result["completed"] + " Completed";

//The above example would get the request and completed task count from the default
//route. If you would like to retrieve values from a different route, you can specify
//this as well. It can be done like this:
$result = $limitrr->get(["discriminator" => $ip, "route" => "exampleRouteName"]);
echo $result["requests"] . " Requests made through the route exampleRouteName";
echo $result["completed"] . " Completed Tasks made through the route exampleRouteName";

//You may also only fetch only one type of value - instead of both requests and completed.
$result = $limitrr->get(["discriminator" => $ip, "route" => "exampleRouteName", "type" => "completed"]);
echo "${result} Completed tasks made through the route exampleRouteName";
```

### Complete action/task count

#### limitrr->complete()

**Returns:** Integer

```php
$limitrr->get([
    "discriminator" => $discriminator, //Required
    "route" => $route, //Not required, default is assumed
]);
```

##### ->complete() Parameters

***Must be passed into the function via an array***

- **discriminator:** **Required** Where discriminator is the thing being limited (e.g x amount of completed actions per discriminator)
- **route**: *String* What route should the values be inserted into? If this is not set, it will get the counts from the `default` route

### Removal of values from certain request/completed keys

#### limitrr->reset()

**Returns:** Boolean

```php
$limitrr->reset([
    "discriminator" => $discriminator, //Required
    "route" => $route, //Not required, default is assumed,
    "type" => $type //Not required
]);
```

##### ->reset() Parameters

***Must be passed into the function via an array***

- **discriminator:** **Required** Where discriminator is the thing being limited (e.g x amount of completed actions per discriminator)
- **route**: *String* What route should the values be reset from? If this is not set, it will reset the counts from the `default` route
- **type**: *String* Which count do you wish to be reset? `requests` or `completed`? If this is not set, both will be removed.

```php
//Where discriminator is the thing being limited
//e.g x amount of completed actions/requests per discriminator
//This will remove both the amount of requests and completed action count
$limitrr->reset(["discriminator" => $discriminator]);

//This tends to be the user's IP.
$limitrr->reset(["discriminator" => $ip]);

//If you wish to reset counts from a particular route, this can be done as well.
//As the type is not specified, it will remove both the request and completed count
$result = $limitrr->reset([
    "discriminator" => $ip,
    "route" => "exampleRouteName"
]);
if ($result) {
    echo "Requests removed from the route exampleRouteName";
} else {
    //Do something else
}

//If you want to remove either one of the amount of requests or completed actions.
//but not the other, this can be done as well.
//The value passed in can either be "requests" or "completed".
//In this example, we will be removing the request count for a certain IP
$result = $limitrr->reset([
    "discriminator" => $ip,
    "type" => "requests"
]);
if ($result) {
    echo "Request count for the specified IP were removed"
} else {
    //do something else
}
```

## Configuration

### redis

**Required:** false

**Type:** Array OR String

**Description:** Redis connection information.

***Either pass in a string containing the URI of the redis instance or an object containing the connection information:***

- **port**: *Integer* Redis port. Defaults to: `6379`
- **host**: *String* Redis hostname. Defaults to: `"127.0.0.1"`
- **password**: *String* Redis password. Defaults to: `""`
- **database**: *Integer* Redis DB. Defaults to: `0`

#### Example of the redis array/string that could be passed into Limitrr

```php
    //Pass in a string containing a redis URI.
    "redis" => "redis://127.0.0.1:6379/0"
    //Alternatively, use an array with the connection information.
    "redis" => [
        "port" => 6379, //Redis Port. Required: false. Defaults to 6379
        "host" => "127.0.0.1", //Redis hostname. required: False. Defaults to "127.0.0.1".
        "password" => "mysecretpassword1234", //Redis password. Required: false. Defaults to null.
        "database" => 0 //Redis database. Required: false. Defaults to 0.
    ]
```

### options

**Required:** false

**Type:** Array

**Description:** Various options to do with Limitrr.

- **keyName:** String The keyname all of the requests will be stored under. This is mainly for aesthetic purposes and does not affect much. However, this should be changed on each initialization of the main class to prevent conflict. Defaults to: `"limitrr"`
- **errorStatusCode:** Integer Status code to return when the user is being rate limited. Defaults to `429` (Too Many Requests)

#### Example of the options object that could be passed into Limitrr

```php
"options" => [
    "keyName" => "myApp", //The keyname all of the requests will be stored under. Required: false. Defaults to "limitrr"
    "errorStatusCode" => 429 //Should important errors such as failure to connect to the Redis keystore be caught and displayed?
]
```

### routes

**Required**: false

**Type**: Array

**Description**: Define route restrictions.

Inside the routes object, you can define many separate routes and set custom rules within them. The custom rules you can set are:

- **requestsPerExpiry**: *Integer* How many requests can be accepted until user is rate limited? Defaults to: `100`
- **completedActionsPerExpiry**: *Integer* How many completed actions can be accepted until the user is rate limited? This is useful for certain actions such as registering a user - they can have a certain amount of requests but a different (obviously smaller) amount of "completed actions". So if users have recently been successfully registered multiple times under the same IP (or other discriminator), they can be rate limited. They may be allowed 100 requests per certain expiry for general validation and the like, but only a small fraction of that for intensive procedures. Defaults to the value in `requestsPerExpiry` or `5` if not set.
- **expiry**: *Integer* How long should the requests be stored (in seconds) before they are set back to 0? If set to -1, values will never expire and will stay that way indefinitely or must be manually removed. Defaults to: `900` (15 minutes)
- **completedExpiry**: *Integer* How long should the "completed actions" (such as the amount of users registered from a particular IP or other discriminator) be stored for (in seconds) before it is set back to 0? If set to -1, such values will never expire and will stay that way indefinitely or must be manually removed. Defaults to the value in `expiry` or `900` (15 minutes) if not set.
- **errorMsgs**: *Object* Seperate error messages for too many requests and too many completed actions. They have been given the respective key names "requests" and "actions". This will be returned to the user when they are being rate limited. If no string was set in `requests`, it will default to `"As you have made too many requests, you are being rate limited."`. Furthermore, if a value has not been set in `completed`, it will resolve to the string found in `requests`. Or, if that wasn't set either, `"As you performed too many successful actions, you have been rate limited."` will be it's value.

#### Example of the routes array

```php
"routes" => [
    //Overwrite default route rules - not all of the keys must be set,
    //only the ones you wish to overwrite
    "default" => [
        "expiry": 1000
    ],
    "exampleRoute" => [
        "requestsPerExpiry" => 100,
        "completedActionsPerExpiry" => 5,
        "expiry" => 900,
        "completedExpiry" => 900,
        "errorMsgs" => [
            "requests" => "As you have made too many requests, you are being rate limited.",
            "completed" => "As you performed too many successful actions, you have been rate limited."
        ]
    ],
    //If not all keys are set, they will revert to
    //the default values
    "exampleRoute2" => [
        "requestsPerExpiry" => 500
    ]
]
```