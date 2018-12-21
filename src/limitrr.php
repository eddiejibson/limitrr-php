<?php
/*
 * @Project: limitrr-php
 * @Created Date: Tuesday, December 11th 2018, 10:23:30 am
 * @Author: Edward Jibson
 * @Last Modified Time: December 21st 2018, 7:05:34 pm
 * @Last Modified By: Edward Jibson
 */
namespace eddiejibson\limitrr;

// use Psr\Http\Message\RequestInterface;
// use Psr\Http\Message\ResponseInterface;

class Limitrr
{
    public function __construct(array $conf = [])
    {
        if (!isset($conf["redis"])) {
            $conf["redis"] = [];
        }
        if (isset($conf["options"]["mw"]) && $conf["options"]["mw"]) {
            $this->options = [
                "keyName" => (isset($conf["options"]["keyName"]) ? $conf["options"]["keyName"] : "limitrr"),
            ];
        } else {
            $conf["routes"]["default"] = [
                "requestsPerExpiry" => (isset($conf["routes"]["default"]["requestsPerExpiry"]) ? $conf["routes"]["default"]["requestsPerExpiry"] : 100),
                "completedActionsPerExpiry" => (isset($conf["routes"]["default"]["completedActionsPerExpiry"]) ? $conf["routes"]["default"]["completedActionsPerExpiry"] : 5),
                "expiry" => (isset($conf["routes"]["default"]["expiry"]) ? $conf["routes"]["default"]["expiry"] : 900),
                "completedExpiry" => (isset($conf["routes"]["default"]["completedExpiry"]) ? $conf["routes"]["default"]["completedExpiry"] : 900),
                "errorMsgs" => [
                    "requests" => (isset($conf["routes"]["default"]["errorMsgs"]["requests"]) ? $conf["routes"]["default"]["errorMsgs"]["requests"] : "As you have made too many requests, you have been rate limited."),
                    "actions" => (isset($conf["routes"]["default"]["errorMsgs"]["completed"]) ? $conf["routes"]["default"]["errorMsgs"]["completed"] : "As you performed too many successful actions, you are being rate limited."),
                ],
            ];
            $this->options = [
                "keyName" => (isset($conf["options"]["keyName"]) ? $conf["options"]["keyName"] : "limitrr"),
                "errorStatusCode" => (isset($conf["options"]["errorStatusCode"]) ? $conf["options"]["errorStatusCode"] : 429),
            ];
            $this->routes = $this->setDefaultToUndefined($conf["routes"], $conf["routes"]["default"]);
        }
        return $this->connect($conf["redis"]);
    }

    public function __destruct()
    {
        $this->db = null;
        return true;
    }

    private function connect(array $conf = [])
    {
        try {
            if (!isset($conf["uri"])) {
                $conf["host"] = (isset($conf["host"]) ? $conf["host"] : "127.0.0.1");
                $conf["port"] = (isset($conf["port"]) ? $conf["port"] : 6379);
            } else {
                $conf = strval($conf["uri"]);
            }
            $conf["database"] = (isset($conf["database"]) ? $conf["database"] : 0);
            $this->db = $client = new \Predis\Client($conf);
            $client->connect();
        } catch (\Predis\Connection\ConnectionException $e) {
            $msg = $e->getMessage();
            throw new \Exception("Limitrr: Could not connect to the Redis keystore. ${msg}", 1);
        }
    }

    public function limit($opts = [], $req, $res, $next)
    {
        $route = (isset($opts["route"]) ? $opts["route"] : "default");
        if ($req->hasHeader("CF-Connecting-IP")) {
            $ip = $req->getHeader("CF-Connecting-IP");
        } elseif ($req->hasHeader("X-Forwarded-For")) {
            $ip = $req->getHeader("X-Forwarded-For");
        } elseif ($route == "test") { //For unit testing
            $ip = "test";
        } else {
            $ip = $req->getServerParam('REMOTE_ADDR');
        }
        $keyName = $this->options["keyName"];
        $result = $this->db->pipeline()
            ->get("limitrr:${keyName}:${ip}:${route}:requests")
            ->get("limitrr:${keyName}:${ip}:${route}:completed")
            ->ttl("limitrr:${keyName}:${ip}:${route}:requests")
            ->ttl("limitrr:${keyName}:${ip}:${route}:completed")
            ->execute();
        if ($result) {
            if (!$result[0]) {
                $result[0] = -2;
            }
            if (!$result[1]) {
                $result[1] = -2;
            }
            if (0 > $result[2] || !$result[2]) {
                $result[2] = 0;
            }
            if (0 > $result[3] || !$result[3]) {
                $result[3] = 0;
            }
            if ($result[0] >= $this->routes[$route]["requestsPerExpiry"]) {
                return $res->withJson([
                    "error" => $this->routes[$route]["errorMsgs"]["requests"],
                ], $this->options["errorStatusCode"]);
            } elseif ($result[1] >= $this->routes[$route]["completedActionsPerExpiry"]) {
                return $res->withJson([
                    "error" => $this->routes[$route]["errorMsgs"]["completed"],
                ], $this->options["errorStatusCode"]);
            } elseif ($result[0] > -2) {
                try {
                    $result = $this->db->incr("limitrr:${keyName}:${ip}:${route}:requests");
                    return $next($req, $res);
                } catch (\Exception $e) {
                    $this->handleError($e);
                }
            } else {
                try {
                    $result = $this->db->pipeline()
                        ->incr("limitrr:${keyName}:${ip}:${route}:requests")
                        ->expire("limitrr:${keyName}:${ip}:${route}:requests", $this->routes[$route]["requestsPerExpiry"])
                        ->execute();
                    return $next($req, $res);
                } catch (\Exception $e) {
                    $this->handleError($e);
                }
            }
        }
    }

    public function complete(array $opts)
    {
        $keyName = $this->options["keyName"];
        $discriminator = $opts["discriminator"];
        $route = (isset($opts["route"]) ? $opts["route"] : "default");
        $currentResult = $this->db->get("limitrr:${keyName}:${discriminator}:${route}:completed");
        if ($currentResult) {
            try {
                $result = $this->db->incr("limitrr:${keyName}:${discriminator}:${route}:completed");
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            if ($result > $currentResult) {
                return $result;
            } else {
                throw new \Exception("Limitrr: Could not complete values", 1);
            }
        } else {
            try {
                $result = $this->db->pipeline()
                    ->incr("limitrr:${keyName}:${discriminator}:${route}:completed")
                    ->expire("limitrr:${keyName}:${discriminator}:${route}:requests", $this->routes[$route]["completedActionsPerExpiry"])
                    ->execute();
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            if ($result[0] > 0 && $result[1]) {
                return $result[0];
            } else {
                throw new \Exception("Limitrr: Could not complete values", 1);
            }
        }
    }

    public function get(array $opts)
    {
        $keyName = $this->options["keyName"];
        $discriminator = $opts["discriminator"];
        $route = (isset($opts["route"]) ? $opts["route"] : "default");
        if (!isset($opts["type"])) {
            try {
                $result = $this->db->pipeline()
                    ->get("limitrr:${keyName}:${discriminator}:${route}:requests")
                    ->get("limitrr:${keyName}:${discriminator}:${route}:completed")
                    ->execute();
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            return [
                "requests" => ($result[0] ? $result[0] : 0),
                "completed" => ($result[1] ? $result[0] : 0),
            ];
        } else {
            try {
                $result = $this->db->get("limitrr:${keyName}:${discriminator}:${route}:${type}");
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            return ($result ? $result : 0);
        }
    }

    public function reset(array $opts)
    {
        $keyName = $this->options->keyName;
        $discriminator = $opts["discriminator"];
        $route = (isset($opts["route"]) ? $opts["route"] : "default");
        if (isset($opts["type"])) {
            try {
                $result = $this->db->pipeline()
                    ->del("limitrr:${keyName}:${discriminator}:${route}:requests")
                    ->del("limitrr:${keyName}:${discriminator}:${route}:completed")
                    ->execute();
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            if ($result && $result[0] && $result[1]) {
                return true;
            } else {
                throw new \Exception("Limitrr: Could not reset values", 1);
            }
        } else {
            try {
                $result = $this->db->del("limitrr:${keyName}:${discriminator}:${route}:${type}");
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            if ($result) {
                return true;
            } else {
                throw new \Exception("Limitrr: Could not reset values", 1);
            }
        }
    }

    private function handleError(\Exception $e)
    {
        $msg = $e->getMessage();
        throw new \Exception("Limitrr : An error was encountered . ${msg} ", 1);
    }

    private function setDefaultToUndefined(array $routes, array $default)
    {
        foreach ($routes as $key => $value) {
            if ($key != " default ") {
                foreach ($default as $defaultKey => $defaultValue) {
                    if (!isset($routes[$key][$defaultKey])) {
                        $routes[$key][$defaultKey] = $defaultValue;
                    }
                }
            }
        }
        $routes["default"] = $default;
        return $routes;
    }

}

class RateLimitMiddleware
{
    public function __construct(Limitrr $limitrr, array $opts = [])
    {
        $this->limitrr = $limitrr;
        $this->opts = $opts;
        return true;
    }
    // RequestInterface  $req, ResponseInterface  $res, callable  $next
    public function __invoke($req, $res, $next)
    {
        return $this->limitrr->limit($this->opts, $req, $res, $next);
    }
}

class GetIpMiddleware
{
    public function __invoke($req, $res, $next)
    {
        if ($req->hasHeader("CF-Connecting-IP")) {
            $ip = $req->getHeader("CF-Connecting-IP");
        } elseif ($req->hasHeader("X-Forwarded-for")) {
            $ip = $req->getHeader("X-Forwarded-for");
        } else {
            $ip = $req->getServerParam("REMOTE_ADDR");
        }
        $req = $req->withAttribute("realip ", $ip);
        return $next($req, $res);
    }
}