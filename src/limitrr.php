<?php
/*
 * @Project: limitrr-php
 * @Created Date: Tuesday, December 11th 2018, 10:23:30 am
 * @Author: Edward Jibson
 * @Last Modified Time: December 22nd 2018, 11:46:41 pm
 * @Last Modified By: Edward Jibson
 */
namespace eddiejibson\limitrr;

class Limitrr
{
    public function __construct(array $conf = [])
    {
        if (!isset($conf["redis"])) {
            $conf["redis"] = [];
        }
        if (isset($conf["options"]["mw"]) && $conf["options"]["mw"]) {
            $this->options = [
                "keyName" => $conf["options"]["keyName"] ?? "limitrr",
            ];
        } else {
            $conf["routes"]["default"] = [
                "requestsPerExpiry" => $conf["routes"]["default"]["requestsPerExpiry"] ?? 100,
                "completedActionsPerExpiry" => $conf["routes"]["default"]["completedActionsPerExpiry"] ?? 5,
                "expiry" => $conf["routes"]["default"]["expiry"] ?? 900,
                "completedExpiry" => $conf["routes"]["default"]["completedExpiry"] ?? 900,
                "errorMsgs" => [
                    "requests" => $conf["routes"]["default"]["errorMsgs"]["requests"] ?? "As you have made too many requests, you have been rate limited.",
                    "actions" => $conf["routes"]["default"]["errorMsgs"]["completed"] ?? "As you performed too many successful actions, you are being rate limited.",
                ],
            ];
            $this->options = [
                "keyName" => $conf["options"]["keyName"] ?? "limitrr",
                "errorStatusCode" => $conf["options"]["errorStatusCode"] ?? 429,
            ];
            $this->routes = $this->setDefaultToUndefined($conf["routes"], $conf["routes"]["default"]);
        }
        $this->connect($conf["redis"]);
    }

    public function __destruct()
    {
        $this->db = null;
    }

    private function connect(array $conf = [])
    {
        try {
            if (!isset($conf["uri"])) {
                $conf["host"] = $conf["host"] ?? "127.0.0.1";
                $conf["port"] = $conf["port"] ?? 6379;
            } else {
                $conf = (string)$conf["uri"];
            }
            $conf["database"] = $conf["database"] ?? 0;
            $this->db = $client = new \Predis\Client($conf);
            $client->connect();
        } catch (\Predis\Connection\ConnectionException $e) {
            $msg = $e->getMessage();
            throw new \Exception("Limitrr: Could not connect to the Redis keystore. ${msg}", 1);
        }
    }

    public function limit(array $opts = [], $req, $res, $next)
    {
        $route = $opts["route"] ?? "default";
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
        $key = "limitrr:${keyName}:${ip}:${route}";
        $result = $this->db->pipeline()
            ->get("${key}:requests")
            ->get("${key}:completed")
            ->ttl("${key}:requests")
            ->ttl("${key}:completed")
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
                    $result = $this->db->incr("${key}:requests");
                    return $next($req, $res);
                } catch (\Exception $e) {
                    $this->handleError($e);
                }
            } else {
                try {
                    $result = $this->db->pipeline()
                        ->incr("${key}:requests")
                        ->expire("${key}:requests", $this->routes[$route]["requestsPerExpiry"])
                        ->execute();
                    return $next($req, $res);
                } catch (\Exception $e) {
                    $this->handleError($e);
                }
            }
        }
    }

    public function complete(array $opts) : int
    {
        $keyName = $this->options["keyName"];
        $discriminator = $opts["discriminator"];
        $route = $opts["route"] ?? "default";
        $key = "limitrr:${keyName}:${discriminator}:${route}";
        $currentResult = $this->db->get("${key}:completed");
        if ($currentResult) {
            try {
                $result = $this->db->incr("${key}:completed");
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
                    ->incr("${key}:completed")
                    ->expire("${key}:requests", $this->routes[$route]["completedActionsPerExpiry"])
                    ->execute();
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            if ($result[0] > 0 && isset($result[1])) {
                return $result[0];
            } else {
                throw new \Exception("Limitrr: Could not complete values", 1);
            }
        }
    }

    public function get(array $opts) : array
    {
        $keyName = $this->options["keyName"];
        $discriminator = $opts["discriminator"];
        $route = $opts["route"] ?? "default";
        $key = "limitrr:${keyName}:${discriminator}:${route}";
        if (!isset($opts["type"])) {
            try {
                $result = $this->db->pipeline()
                    ->get("${key}:requests")
                    ->get("${key}:completed")
                    ->execute();
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            return [
                "requests" => (int)($result[0] ?? 0),
                "completed" => (int)($result[1] ?? 0)
            ];
        } else {
            $type = $opts["type"];
            try {
                $result = $this->db->get("${key}:${type}");
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            return [
                (string)$type => (int)($result ?? 0)
            ];
        }
    }

    public function reset(array $opts) : bool
    {
        $keyName = $this->options["keyName"];
        $discriminator = $opts["discriminator"];
        $route = $opts["route"] ?? "default";
        $key = "limitrr:${keyName}:${discriminator}:${route}";
        if (!isset($opts["type"])) {
            try {
                $result = $this->db->pipeline()
                    ->del("${key}:requests")
                    ->del("${key}:completed")
                    ->execute();
            } catch (\Exception $e) {
                $this->handleError($e);
            }
            if ($result) {
                return true;
            } else {
                throw new \Exception("Limitrr: Could not reset values", 1);
            }
        } else {
            $type = $opts["type"];
            try {
                $result = $this->db->del("${key}:${type}");
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

    private function setDefaultToUndefined(array $routes, array $default) : array
    {
        foreach ($routes as $key => $value) {
            if ($key != "default") {
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
