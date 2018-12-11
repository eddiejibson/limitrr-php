<?php
/*
 * @Project: limitrr-php
 * @Created Date: Tuesday, December 11th 2018, 10:23:30 am
 * @Author: Edward Jibson
 * @Last Modified Time: December 11th 2018, 10:38:50 am
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




}