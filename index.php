<?php
    include('database.class.php');
    
    header('Content-Type: application/json; charset=utf-8');
    
    $db;
    
    abstract class Errors {
        const UNKNOWN_ERROR = 0;
        const INVALID_JSON = 1;
        const SERVICE_NOT_AVAILABLE = 2;
        const INVALID_DATA = 3;
        const NO_TOKEN_PROVIDED = 4;
        const TOKEN_NOT_FOUND = 5;
        const MUST_REFRESH_PICTURE = 6;
        const INVALID_NAME = 7;
        const GOOGLE_API_ERROR = 8;
        const NO_FACE_DETECTED = 9;
        const DUPLICATE_IMAGE = 10;
        const INVALID_TARGET = 11;
        const TARGET_ACTIVE = 12;
        const TARGET_NOT_FOUND = 13;
        const BUSY = 14; // A minute since the user has sent the nudge still hasn't passed
        const NUDGE_DONE = 15; // The target has already answered on the nudge
        const NOT_ANSWERED = 16; // Must answer to the nudge first (before sending another one during busy interval)
        const TARGET_BUSY = 17; // The target is nudging or being nudged currently
        const TARGET_DONE = 18; // Already done with the target
        const NUDGE_NOT_FOUND_OR_ANSWERED = 19;
        const NOT_ACTIVE = 20;
    }
    
    $content = json_decode(file_get_contents("php://input"), true);
    if (empty($content)) {
        err(Errors::INVALID_JSON);
    }
    
    $db = new Database();
    if (!$db) {
        err(Errors::SERVICE_NOT_AVAILABLE);
    }
    
    include('mb_trim.func.php');
    
    define('MAX_DISTANCE', 500);
    
    function sendquery($query, $params = null, $error = null) {
        global $db;
        $db->query($query);
        if ($params != null) {
            $db->bind($params);
        }
        $r = $db->execute();
        if ($r === true) return true;
        if (is_array($error)) {
            foreach ($error as $num => $msg) {
                if ($r == $num) {
                    err($msg);
                    return;
                }
            }
            err();
        } else {
            err($error);
        }
    }
    
    $finishing; $msg_new;
    function err($msg = null) {
        global $finishing, $msg_new;
        $msg_new = $msg;
        if (!isset($finishing)) {
            finish();
        }
    }
    $skip_check = false;
    define('SELECT_QUERY', 
        "name,
        img_name,
        img_refreshed,
        ROUND(".distance_sql()." + ABS(:alt - alt), NOT ISNULL(active)) AS distance,
        TIMESTAMPDIFF(SECOND, main.accessed, NOW()) AS last_seen,");
    function finish() {
        global $finishing, $msg_new, $content, $db, $record, $not_deviceid;
        if (!isset($finishing)) {
            $finishing = null;
        }
        $answered;
        if ($not_deviceid && ($finishing || is_bool($msg_new))) {
            global $skip_check, $coords;
            $results = [];
            if (!$skip_check && $msg_new !== true) {
                sendquery("
                    SELECT SQL_NO_CACHE 
                        `from`, `to`, answer, ISNULL(active) AS not_active, not_delivered,
                        ".SELECT_QUERY."
                        ST_X(coords) AS lat,
                        ST_Y(coords) AS lng,
                        alt,
                        UNIX_TIMESTAMP(CONVERT_TZ(sent, '-04:00', @@session.time_zone)) AS nudge_created
                    FROM nudge
                    INNER JOIN main ON
                        (img_name = `to` OR img_name = `from`) AND img_name <> :img_name
                        AND (active IS NOT NULL OR answer IS NOT FALSE AND ".INTERVAL_SQL.")
                    ORDER BY sent DESC
                    LIMIT 1
                ", $coords + [':img_name' => $record['img_name']]);
            }
            if ($finishing || is_bool($msg_new)) {
                $result = !$skip_check && $msg_new !== true ? $db->single() : false;
                if ($result) {
                    //if ($result['not_active'] || $result['distance'] <= MAX_DISTANCE) { //
                        if ($result['not_delivered'] && $result['from'] != $record['img_name']) {
                            sendquery("
                                UPDATE nudge
                                SET not_delivered = 0
                                WHERE `from` = :from AND `to` = :to
                                LIMIT 1
                            ", [':from' => $result['from'], ':to' => $record['img_name']]);
                        } else if (($finishing || $msg_new === false) && $result['not_active'] && $result['answer']) {
                            sendquery("
                                UPDATE main SET active = :first WHERE img_name = :second LIMIT 1;
                                UPDATE main SET active = :second WHERE img_name = :first LIMIT 1
                            ", [':first' => $result['from'], ':second' => $result['to']]);
                            if ($finishing || $msg_new === false) {
                                $result['not_active'] = false;
                            }
                        }
                        $answered = !$result['not_active'];
                        $initiator = $result['from'] == $record['img_name'];
                        if ($result['not_active']) {
                            unset($result['lat'], $result['lng'], $result['alt']);
                        }
                        unset(
                            $result['from'],
                            $result['to'],
                            $result['answer'],
                            $result['not_active'],
                            $result['not_delivered']);
                        $results = [$result];
                    /*} else { // /*
                        cancel_active();
                    } // */
                }
                if (!isset($answered) && !is_int($finishing) && ($finishing || is_bool($msg_new))) {
                    sendquery("
                        SELECT SQL_NO_CACHE 
                            ".SELECT_QUERY."
                            IF(not_delivered IS NULL OR `from` <> :img_name AND `to` <> :img_name, -1, not_delivered) AS not_nudged
                        FROM main
                        LEFT JOIN nudge ON `from` = img_name OR `to` = img_name
                        WHERE img_name <> :img_name AND NOT to_refresh
                            AND (`from` <> :img_name AND `to` <> :img_name OR answer IS NULL)
                            AND active IS NULL
                        GROUP BY img_name
                        ".//HAVING last_seen <= 60 AND distance <= ".MAX_DISTANCE. //
                      " ORDER BY distance, last_seen, not_nudged
                    ", $coords + [':img_name' => $record && isset($record['img_name']) ? $record['img_name'] : $content['img_name']]);
                    if ($finishing || is_bool($msg_new)) {
                        $results = $db->resultset();
                    }
                }
            }
        }
        echo json_encode(
            (!is_bool($msg_new) ? ['error' => !is_null($msg_new) ? $msg_new : Errors::UNKNOWN_ERROR] : []) + (is_bool($msg_new) || $finishing ? 
            ($msg_new === true || $content['device_id'] ? ($content['token'] || isset($content['image']) ?
            ['token' => $content['token'] ? $content['token'] : $record['token']] : ['name' => $record['name']]) : [])
            + (!is_int($finishing) || isset($answered) ? (isset($answered) ? ['initiator' => $initiator, 'answered' => $answered] : [])
            + (isset($results) ? ['results' => $results] : []) : []) : []), JSON_NUMERIC_CHECK);
        
        if ($db) {
            $db->close();
        }
        die();
    }
    
    function distance_sql($lat = ':lat', $lng = ':lng') {
        return "( 6371000 * acos( cos( radians($lat) ) * cos( radians( ST_X(main.coords) ) ) * cos( radians( ST_Y(main.coords) ) - radians($lng) ) + sin( radians($lat) ) * sin(radians(ST_X(main.coords))) ) )";
    }
    define('INTERVAL_SQL', "sent > NOW() - INTERVAL 1 MINUTE");
    
    function distance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000) {
        $latFrom = deg2rad($latitudeFrom);
        $latTo = deg2rad($latitudeTo);
        return 2 * asin(sqrt(pow(sin(($latTo - $latFrom) / 2), 2) +
        cos($latFrom) * cos($latTo) * pow(sin((deg2rad($longitudeTo) - deg2rad($longitudeFrom)) / 2), 2))) * $earthRadius;
    }

    function str_random($length = 8) {
        return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $length)), 0, $length);
    }
    
    function checkstr(&$str, $regex) {
        if (!isset($str) || !is_string($str) || !preg_match($regex, $str)) {
            $str = false;
        }
    }
    function chktoken(&$str) {
        checkstr($str, '/^[a-zA-Z0-9]{8}$/');
    }
    function no_base64_image(&$data, &$img) {
        $img = @imagecreatefromstring(base64_decode($data));
        if (!$img || imagesx($img) <> 480 || imagesy($img) <> 480) {
            return true;
        }
    }
    
    function cancel_active() {
        global $finishing, $msg_new, $record, $db;
        sendquery("UPDATE main SET active = NULL WHERE active = :img_name LIMIT 1", [':img_name' => $record['img_name']]);
        if ($db->rowCount() != 0) {
            sendquery("UPDATE main SET active = NULL WHERE img_name = :img_name LIMIT 1;
                       UPDATE nudge SET answer = FALSE
                       WHERE (`from` = :img_name OR `to` = :img_name)
                       ORDER BY sent DESC LIMIT 1"
            , [':img_name' => $record['img_name']]);
        }
    }

    chktoken($content['token']);
    if (!$content['token']) {
        checkstr($content['device_id'], '/^[a-f0-9]{16}$/');
        if (!$content['device_id']) {
            err(Errors::INVALID_DATA);
        }
    } else {
        $content['device_id'] = false;
    }
    $not_deviceid = !$content['device_id'] || isset($content['image']);
    if ($not_deviceid) {
        $coords = [];
        foreach (array('lat', 'lng', 'alt') as $i) {
            if (!isset($content[$i]) || filter_var($content[$i], FILTER_VALIDATE_FLOAT) === false) {
                err(Errors::INVALID_DATA);
            }
            $coords[':'.$i] = strval($content[$i]);
        }
    }
    sendquery("
        SELECT SQL_NO_CACHE 
            token,
            name,
            img_name,
            to_refresh,
            ST_X(init_coords) AS init_lat,
            ST_Y(init_coords) AS init_lng,
            init_alt,
            ISNULL(active) AS not_active
        FROM main
        WHERE ".($content['token'] ? 'token' : 'device_id')." = :target
        LIMIT 1
    ", [':target' => $content['token'] ? $content['token'] : $content['device_id']]);
    $record = $db->single();
    if (!$record && ($content['token'] || !isset($content['image']))) {
        err($content['token'] ? Errors::TOKEN_NOT_FOUND : -1);
    }
    if ($record['to_refresh']) { // || !file_exists('img/'+$record['img_name'])
        $finishing = 1;
    } else if (distance($content['lat'], $content['lng'], $record['init_lat'], $record['init_lng']) > MAX_DISTANCE
    || abs($content['alt'] - $record['init_alt']) > MAX_DISTANCE) {
        $finishing = 2;
    }
    
    switch (true) {
    case $content['token'] && !isset($finishing) && $record['not_active'] && isset($content['nudge']):
        chktoken($content['nudge']);
        if (!$content['nudge']) {
            err(Errors::INVALID_TARGET);
        }
        //$finishing = true;
        sendquery("
            SELECT SQL_NO_CACHE 
                `from`, `to`,
                ".INTERVAL_SQL." AS busy,
                ISNULL(answer) AS not_answered,
                NOT ISNULL(active) AS active
            FROM nudge
            JOIN main ON main.img_name = :target
            WHERE
                active IS NOT NULL
                OR (`from` IN (:img_name, :target) OR `to` IN (:img_name, :target)) AND ".INTERVAL_SQL."
                OR `from` IN (:img_name, :target) AND `to` IN (:img_name, :target) AND answer IS NOT NULL
            ORDER BY sent DESC
            LIMIT 1
        ", [':img_name' => $record['img_name'],
            ':target' => $content['nudge']]);
        if ($db->rowCount() != 0) {
            $result = $db->single();
            if ($result['active']) {
                err(Errors::TARGET_ACTIVE);
            }
            if ($result['busy']) {
                if ($result['from'] == $record['img_name']) {
                    err(Errors::BUSY);
                } else if ($result['to'] == $record['img_name']) {
                    if ($result['not_answered']) {
                        err(Errors::NOT_ANSWERED);
                    }
                } else {
                    err(Errors::TARGET_BUSY);
                }
            }
            if ($result['from'] == $content['nudge']) {
                err(Errors::TARGET_DONE);
            }
            if ($result['to'] == $content['nudge']) {
                err(Errors::NUDGE_DONE);
            }
        }
        $params = [':img_name' => $record['img_name'], ':target' => $content['nudge']];
        sendquery("DELETE FROM nudge WHERE `from` = :target AND `to` = :img_name", $params);
        sendquery("
            INSERT INTO nudge (`from`, `to`)
            SELECT SQL_NO_CACHE :img_name, img_name
            FROM main WHERE
                img_name = :target
                ".//AND ABS(:alt - alt) <= ".MAX_DISTANCE." AND ".distance_sql()." <= ".MAX_DISTANCE. //
          " LIMIT 1
            ON DUPLICATE KEY UPDATE
                not_delivered = not_delivered + 1,
                sent = NOW()
        ", /*$coords +, */ $params);
        if ($db->rowCount() == 0) {
            err(Errors::TARGET_NOT_FOUND);
        }
        break;

    case $content['token'] && $record['not_active'] && isset($content['answer']):
        /*if (!isset($finishing)) {
            $finishing = true;
        }*/
        sendquery("
            UPDATE nudge
            SET answer = :answer
            WHERE
                `to` = :to
                AND ".INTERVAL_SQL."
                AND answer IS NULL AND not_delivered = 0
            LIMIT 1
        ", [':answer' => (bool)$content['answer'],
            ':to' => $record['img_name']]);
        if ($db->rowCount() == 0) {
            err(Errors::NUDGE_NOT_FOUND_OR_ANSWERED);
        }
        break;

    case $content['token'] && isset($content['cancel']) && (bool)$content['cancel']:
        /*if (!isset($finishing)) {
            $finishing = true;
        }*/
        if (!$record['not_active']) {
            cancel_active();
        }
        if ($record['not_active'] || $db->rowCount() == 0) {
            err(Errors::NOT_ACTIVE);
        }
        $skip_check = true;
        break;

    case $not_deviceid:
        $has_img = (!$content['token'] || isset($finishing)) && isset($content['image']);
        
        if ($has_img) {
            unset($finishing);
            $img = null;
            if ((!$record && (!isset($content['name']) || !is_string($content['name']))) || no_base64_image($content['image'], $img)) {
                err(Errors::INVALID_DATA);
            }
            if (!$record) {
                $content['name'] = mb_convert_case(mb_trim($content['name']), MB_CASE_TITLE);
                if (!preg_match('/^\p{L}{2,15}( [A-Z]{1}\.)?( \p{L}{1,15})?( \p{L}{4,15})?$/u', $content['name'])) {
                    err(Errors::INVALID_NAME);
                }
            }
            
            /*$curl = curl_init(); // 
            curl_setopt($curl, CURLOPT_URL, 'https://vision.googleapis.com/v1/images:annotate?key=***REMOVED***');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, '{
                "requests": [{
                    "image": {
                        "content":"'.$content['image'].'"
                    },
                    "features": [{
                        "type": "FACE_DETECTION",
                        "maxResults": 1
                    }]
                }]
            }');
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($status != 200) {
                err(Errors::GOOGLE_API_ERROR);
            }
            if (empty(json_decode($response, true)['responses'][0])) {
                err(Errors::NO_FACE_DETECTED);
            }*/
            
            $finishing = null;
            if (!$record) {
                $content['img_name'] = str_random();
                $content['token'] = str_random();
            } else {
                $content['img_name'] = $record['img_name'];
            }
            
            chdir('img');
            imagejpeg($img, $content['img_name'].'-', 100);
            $params = [':img_md5' => hash_file('md5', $content['img_name'].'-'),
                       ':img_size' => filesize($content['img_name'].'-')];
        } else if (isset($finishing)) {
            //unlink($record['img_name']);
            if ($finishing == 2) {
                sendquery("UPDATE main SET to_refresh = TRUE WHERE token = :token LIMIT 1", [':token' => $content['token']]);
            }
            err(Errors::MUST_REFRESH_PICTURE);
        } else if ($content['token']) {
            $params = [];
        } else {
            err(Errors::NO_TOKEN_PROVIDED);
        }
        
        if (!$finishing) {
            $params += [':coords' => 'POINT('.$content['lat'].' '.$content['lng'].')',
                        ':alt' => $content['alt'], ':token' => !$record ? $content['token'] : $record['token']];
            
            if ($record) {
                $query = "
                    UPDATE main
                    SET
                        ".($has_img ? 'init_' : '')."coords = ST_GeomFromText(:coords),
                        ".($has_img ? 'init_' : '')."alt = :alt".($has_img ? ",
                        img_md5 = :img_md5,
                        img_size = :img_size,
                        img_refreshed = img_refreshed + 1,
                        to_refresh = FALSE": '')."
                    WHERE token = :token
                    LIMIT 1
                ";
            } else {
                $query = "
                    INSERT INTO main (name, img_name, init_coords, init_alt, coords, alt, img_md5, img_size, token, device_id)
                    VALUES (:name, :img_name, ST_GeomFromText(:coords), :alt, ST_GeomFromText(:coords), :alt, :img_md5, :img_size, :token, :device_id)
                ";
                $params += [':name' => $content['name'], ':img_name' => $content['img_name'], ':device_id' => $content['device_id']];
            }
            sendquery($query, $params, [1062 => Errors::DUPLICATE_IMAGE]);
            
            if ($has_img) {
                if (!isset($msg_new)) {
                    rename($content['img_name'].'-', $content['img_name']);
                } else {
                    unlink($content['img_name'].'-');
                }
            }
        }
    }
    
    if (!isset($msg_new)) {
        $msg_new = !(bool)$record;
    }
    finish();
?>