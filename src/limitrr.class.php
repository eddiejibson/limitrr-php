<?php
/*
 * @Project: limitrr-php
 * @Created Date: Tuesday, December 11th 2018, 10:23:30 am
 * @Author: Edward Jibson
 * @Last Modified Time: December 15th 2018, 4:13:24 pm
 * @Last Modified By: Edward Jibson
 */

class limitrr
{
    public function __construct(array $conf = [])
    {
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
            $this->db = new Redis();
            $conf["host"] = (isset($conf["host"]) ? $conf["host"] : "127.0.0.1");
            $conf["port"] = (isset($conf["port"]) ? $conf["port"] : 6379);
            $res = $this->db->connect($conf["host"], $conf["port"]);
            if (isset($conf["password"])) {
                $res = $this->db->auth($conf["password"]);
            }
            if ($res) {
                return true;
            } else {
                $this->db = null;
                return false;
            }
        } catch (\Exception $e) {
            throw new Exception("Limitrr: An error was encountered. ${e}", 1);
        }
    }

    public function getIp($request, $response, $next)
    {
        if ($request->hasHeader("CF-Connecting-IP")) {
            $ip = $request->getHeader("CF-Connecting-IP");
        } elseif ($request->hasHeader("X-Forwarded-For")) {
            $ip = $request->getHeader("X-Forwarded-For");
        }
        $request = $request->withAttribute("realip", $ip);
        return $next($request, $response);
    }

    public function test()
    {
        $result = $this->db->incr("limitrrrequests");
        var_dump($result);
    }

    //incomplete idek
    public function limit(array $arr)
    {
        return function ($req, $res, $next) {
            $route = (isset($arr["route"]) ? $arr["route"] : "default");
            if ($request->hasHeader("CF-Connecting-IP")) {
                $ip = $request->getHeader("CF-Connecting-IP");
            } elseif ($request->hasHeader("X-Forwarded-For")) {
                $ip = $request->getHeader("X-Forwarded-For");
            } elseif ($arr["route"] == "test") { //For unit testing
                $ip = "test";
            }
            $keyName = $this->options->keyName;
            $route = $arr["route"];
            $result = $this->db->multi()
                ->get("limitrr:${keyName}:${ip}:${route}:requests")
                ->get("limitrr:${keyName}:${ip}:${route}:completed")
                ->ttl("limitrr:${keyName}:${ip}:${route}:requests")
                ->ttl("limitrr:${keyName}:${ip}:${route}:completed")
                ->exec();
            if ($result) {
                if (!$result[0]) {
                    $result[0] = -2;
                }
                if (!$result[1]) {
                    $result[1] = -2;
                }
                if (0 > $result[2]) {
                    $result[2] = 0;
                }
                if (0 > $result[3]) {
                    $result[3] = 0;
                }
                if ($result[0] >= $this->routes[$route]["requestsPerExpiry"]) {
                    return $res->withJson([
                        "error" => $this->routes[$route]["errorMsgs"]["requests"],
                    ], $this->options["errorStatusCode"]);
                } elseif ($result[1] >= $this->routes[$route]["completedPerExpiry"]) {
                    return $res->withJson([
                        "error" => $this->routes[$route]["errorMsgs"]["completed"],
                    ], $this->options["errorStatusCode"]);
                } elseif ($result[0] > -2) {
                    try {
                        $result = $this->db->incr("limitrr:${keyName}:${ip}:${route}:requests");
                        return $next($request, $response);
                    } catch (\Exception $e) {
                        throw new Exception("Limitrr: An error was encountered. ${e}", 1);
                    }
                } else {
                    try {
                        $result = $this->db->multi()
                            ->incr("limitrr:${keyName}:${ip}:${route}:requests")
                            ->expire("limitrr:${keyName}:${ip}:${route}:requests", $this->routes[$route]["requestsPerExpiry"])
                            ->exec();
                        return $next($request, $response);
                    } catch (\Exception $e) {
                        throw new Exception("Limitrr: An error was encountered. ${e}", 1);
                    }
                }
            }
        };
    }

    public function complete(array $arr)
    {
        $route = $arr["route"] = (isset($arr["route"]) ? $arr["route"] : "default");
        $discriminator = $arr["discriminator"];
        $keyName = $this->options->keyName;
        $result = $this->db-> ->incr("limitrr:${keyName}:${ip}:${route}:completed");
    }

    private function setDefaultToUndefined(array $routes, array $default)
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
include_once "test.php";