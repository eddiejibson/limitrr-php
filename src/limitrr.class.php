<?php
/*
 * @Project: limitrr-php
 * @Created Date: Tuesday, December 11th 2018, 10:23:30 am
 * @Author: Edward Jibson
 * @Last Modified Time: December 11th 2018, 1:34:37 pm
 * @Last Modified By: Edward Jibson
 */
namespace eddiejibson\limitrr;
class limitrr {
    public function __construct(array $conf) {
        return $this->connect($conf);
    }

    public function __destruct() {
        $this->db = null;
        return true;
    }

    private function connect() {
        try {
            $this->db = new Redis();
            $res = $this->db->connect($conf["redis"]["host"], $conf["redis"]["port"]);
            if (isset($conf["redis"]["password"])) {
                $res = $this->db->auth($conf["redis"]["password"]);
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

    public function getIp($request, $response, $next) {
        if ($request->hasHeader("CF-Connecting-IP")) {
            $ip = $request->getHeader("CF-Connecting-IP");
        } elseif ($request->hasHeader("X-Forwarded-For")) {
            $ip = $request->getHeader("X-Forwarded-For");
        }
        $request = $request->withAttribute("realip", $ip);
        return next($request, $response);
    }

    //incomplete idek
    public function limit(array $arr) {
        return function($req, $res, $next) {
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
            $result = $redis->multi()
            ->get("limitrr:${keyName}:${ip}:${route}:requests")
            ->get("limitrr:${keyName}:${ip}:${route}:completed")
            ->ttl("limitrr:${keyName}:${ip}:${route}:requests")
            ->ttl("limitrr:${keyName}:${ip}:${route}:completed")
            ->exec();
        };
    }


}