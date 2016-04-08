<?php

require_once 'html5/html5.inc.php';
require_once 'websocket/websocket.inc.php';

class Robin_IRC {
    /**
     * Filter/channel configuration file, read every ROBIN_TIMEOUT seconds
     */
    const INI = 'robin-irc.ini';

    /**
     * Disable debug printouts by setting DEBUG to 0
     */
    const DEBUG = 1;

    /**
     * Host and port on which to act as an IRC server
     */
    const IRC_HOST = 'localhost';
    const IRC_PORT = 8194;

    /**
     * IRC channel used as a placeholder for unfiltered Robin
     */
    const IRC_CHANNEL = '#general';

    /**
     * Non-configurable constants
     */
    const REDDIT_LOGIN_URL = 'https://www.reddit.com/post/login';
    const REDDIT_ROBIN_URL = 'https://www.reddit.com/robin/';
    const REDDIT_REFERER_URL = 'https://www.reddit.com/login?dest=https%3A%2F%2Fwww.reddit.com%2Frobin%2Fjoin';
    const REDDIT_POST_URL = 'https://www.reddit.com/api/robin/%s/%s';

    const ROBIN_HOST = 'reddit.com';
    const ROBIN_TIMEOUT = 300;

    const IRC_SOCK = 0;
    const ROBIN_SOCK = 1;

    const COLOR_WHITE  = '00';
    const COLOR_BLACK  = '01';
    const COLOR_NAVY   = '02';
    const COLOR_GREEN  = '03';
    const COLOR_RED    = '04';
    const COLOR_BROWN  = '05';
    const COLOR_PURPLE = '06';
    const COLOR_ORANGE = '07';
    const COLOR_YELLOW = '08';
    const COLOR_LIME   = '09';
    const COLOR_TEAL   = '10';
    const COLOR_AQUA   = '11';
    const COLOR_BLUE   = '12';
    const COLOR_PINK   = '13';
    const COLOR_GREY   = '14';
    const COLOR_SILVER = '15';

    protected $prefixes = array();
    protected $body_filters = array();

    protected $ircsock;

    protected $listen_sockets;

    protected $redditnick;
    protected $redditpass;
    protected $redditmodhash;
    protected $redditws;

    protected $robin_url;
    protected $robin_id;
    protected $robin_name;
    protected $robin_cookies;
    protected $robin_last_load_time;

    protected $last_message;

    public function __construct() {
        $this->ircsock = stream_socket_server(
            sprintf('tcp://%s:%s', self::IRC_HOST, self::IRC_PORT),
            $errno, $errmsg
        );
        if (!$this->ircsock) {
            throw new Exception($errmsg, $errno);
        }

        $this->listen();
    }

    public function listen() {
        $this->debug('IRC', 'Listening on port '.self::IRC_PORT);
        while (1) {
            $this->listen_sockets[self::IRC_SOCK] = @stream_socket_accept($this->ircsock);

            if ($this->listen_sockets[self::IRC_SOCK]) {
                stream_set_blocking($this->listen_sockets[self::IRC_SOCK], false);
                $this->debug('IRC', 'Client connected');

                while (isset($this->listen_sockets[self::IRC_SOCK])) {
                    $sockets = $this->listen_sockets;
                    stream_select($sockets, $sockets, $sockets, null);

                    $line = trim(fgets($this->listen_sockets[self::IRC_SOCK], 8192));
                    if ($line && strlen($line)) {
                        $this->debug('IRC<-', $line);
                        if ( strpos($line, ' ') === false ) {
                            $cmd = $line;
                            $msg = array();
                        }
                        else {
                            list($cmd, $msg) = split(' ', $line, 2);
                            // Split arguments on space, except : denotes
                            // the last argument, which may contain spaces
                            $colonpos = strpos($msg, ':');
                            if ( $colonpos === false ) {
                                $msg = split(' ', $msg);
                            }
                            else {
                                $lastarg = substr($msg, $colonpos+1);
                                $msg = split(' ', trim(substr($msg, 0, $colonpos)));
                                array_push($msg, $lastarg);
                            }
                        }
                        call_user_func_array(array($this, 'irc_'.$cmd), $msg);
                    }

                    if (isset($this->listen_sockets[self::ROBIN_SOCK])) {
                        $line = $this->redditws->receive();
                        $this->debug('ROBIN<-', $line);
                        $line_json = json_decode($line, true);
                        call_user_func_array(array($this, 'robin_'.$line_json['type']), array($line_json['payload']));
                    }

                    usleep(500);
                }
                $this->debug('IRC', 'Client quit');
            }
        }
    }

    protected function parse_cookies($headers) {
        $cookies = array();
        foreach (split("\r\n", $headers) as $header) {
            $parts = split(':', $header);
            if (count($parts) >= 2) {
                $h = strtolower(trim($parts[0]));
                $v = trim($parts[1]);
                if ($h == 'set-cookie') {
                    $cookies[] = substr($v, 0, strpos($v, ';'));
                }
            }
        }

        return $cookies;
    }

    public function login() {
        $this->out_irc(null, 'NOTICE', 'AUTH', 'Logging in to Reddit...');
        $c = curl_init();
        $form = http_build_query(array(
            'user' => $this->redditnick,
            'passwd' => $this->redditpass
        ));
        $options = array(
            //CURLOPT_VERBOSE => true,
            CURLOPT_URL => self::REDDIT_LOGIN_URL,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Robin-IRC Bridge/0.0.1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $form,
            CURLOPT_HTTPHEADER => array(
                'Content-type: application/x-www-form-urlencoded',
                'Content-length: '.strlen($form)
            )
        );

        $c = curl_init();
        curl_setopt_array($c, $options);
        $r = curl_exec($c);
        curl_close($c);

        list($headers, $body) = split("\r\n\r\n", $r);

        $this->robin_cookies = $this->parse_cookies($headers);
        $ok = 0;
        foreach ( $this->robin_cookies as $cookie ) {
            if ( strpos($cookie, "reddit_session=") === 0 ) {
                $ok = 1;
                break;
            }
        }
        if ( $ok ) {
            $this->out_irc(null, 'NOTICE', 'AUTH', 'Authenticated.');
            $this->refresh();
        }
        else {
            $this->out_irc(null, 'NOTICE', 'AUTH', 'FAILED to authenticate.');
            throw new Exception;
        }
    }

    protected function refresh() {
        $c = curl_init();
        $options = array(
            CURLOPT_URL => self::REDDIT_ROBIN_URL,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Robin-IRC Bridge/0.0.1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => array(
                'Cookie: ' . join('; ', $this->robin_cookies)
            )
        );
        curl_setopt_array($c, $options);
        $r = curl_exec($c);
        curl_close($c);

        list($headers, $body) = split("\r\n\r\n", $r);
        $html = new \Masterminds\HTML5();
        $dom = $html->loadHTML($body);
        $qp = qp($dom);

        $config = $qp->top('head script')->text();
        $config = preg_replace('/^r\.setup\(/', '', $config);
        $config = preg_replace('/\)if \(\!window.DO_NOT_TRACK\).*$/', '', $config);
        $config_json = json_decode($config, true);

        $this->robin_url  = $config_json['robin_websocket_url'];
        $this->robin_id   = $config_json['robin_room_id'];
        $this->robin_name = $config_json['robin_room_name'];
        $this->users      = array();
        foreach ($config_json['robin_user_list'] as $user) {
            $this->users[strtoupper($user['name'])] = $user;
        }

        $this->redditmodhash = $config_json['modhash'];

        $this->redditws = new WebSocket\Client($this->robin_url);
        $this->redditws->connect();

        $this->listen_sockets[self::ROBIN_SOCK] = $this->redditws->socket;
        if (!$this->listen_sockets[self::ROBIN_SOCK]) {
            throw new Exception($errmsg, $errno);
        }

        $this->robin_last_load_time = time();
        $this->debug('ROBIN', 'Connected.');
        $this->out_irc(null, 'NOTICE', 'AUTH', 'Connected.');

        $ini = parse_ini_file(self::INI, true);
        $this->body_filters = $ini['filters']['filter'];
        $this->prefixes = array();
        foreach ($ini['channels'] as $ccode => $prefix) {
            $this->prefixes[$prefix] = '#'.$ccode;
        }

        $vote = 'INCREASE';
        if (isset($ini['general']['autovote'])) {
            $vote = $ini['general']['autovote'];
        }
        $this->out_robin('vote', $vote);
    }

    protected function out_robin($type, $payload) {
        $this->debug('ROBIN->', sprintf('%s %s', $type, $payload));

        $post_data = array(
            'room_id' => $this->robin_id,
            'room_name' => $this->robin_name,
            'api_type' => 'json',
            'messageClass' => $type
        );
        switch ($type) {
            case 'message':
                $post_data['message'] = $payload;
                break;
            case 'vote':
                $post_data['vote'] = $payload;
                break;
        }
        $post_str = http_build_query($post_data);

        $c = curl_init();
        curl_setopt_array($c, array(
            CURLOPT_URL => sprintf(self::REDDIT_POST_URL, $this->robin_id, $type),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Robin-IRC Bridge/0.0.1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_str,
            CURLOPT_HTTPHEADER => array(
                'Cookie: ' . join('; ', $this->robin_cookies),
                'X-Modhash: '.$this->redditmodhash,
                'X-Requested-With: XMLHTTPRequest',
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: '.strlen($post_str)
            )
        ));
        $r = curl_exec($c);
        curl_close($c);
        print_r($r);
    }

    protected function out_irc($nick, $code, $prefix, $str) {
        if ($str === false) {
            return;
        }

        if ( is_array($prefix) )
            $prefix = join(' ', $prefix);

        if ($nick) {
            $mask = sprintf("%s!%s@%s", $nick, $nick, self::ROBIN_HOST);
        } else {
            $mask = self::IRC_HOST;
        }

        $buf = sprintf(":%s %s %s", $mask, $code, $prefix);
        if ( $str )
            $buf = sprintf("%s :%s", $buf, $str);
        $this->debug('IRC->', $buf);
        if ($this->listen_sockets[self::IRC_SOCK]) {
            fprintf($this->listen_sockets[self::IRC_SOCK], "%s\r\n", $buf);
        }
    }

    public function __call($name, $args) {
        list($protocol, $msg) = split('_', $name, 2);
        switch (strtoupper($protocol)) {
            case 'IRC':
                switch (strtoupper($msg)) {
                    // FIXME: Error checking on number of arguments
                    case 'PASS':
                        $this->redditpass = $args[0];
                        break;

                    case 'NICK':
                        $this->redditnick = $args[0];
                        break;

                    case 'USER':
                        $this->login();
                        $this->out_irc(null, '001', $this->redditnick, sprintf("Welcome to Robin/IRC bridge %s!%s@%s", $this->redditnick, $this->redditnick, self::ROBIN_HOST));
                        $this->out_irc(null, '002', $this->redditnick, "PREFIX=(qov)~@+");
                        $this->irc_motd();
                        break;

                    case 'MOTD':
                        $tally = $this->tally();
                        $this->out_irc(null, '375', $this->redditnick, sprintf("- %s MOTD -", self::ROBIN_HOST));
                        $this->out_irc(null, '372', $this->redditnick, "- Current tally of votes");
                        $this->out_irc(null, '372', $this->redditnick, "- ----------------------");
                        foreach ( array('NOVOTE', 'ABANDON', 'CONTINUE', 'INCREASE') as $key ) {
                            $this->out_irc(null, '372', $this->redditnick, sprintf("-     %5d %-8s", $tally[$key], $key));
                        }
                        $this->out_irc(null, '372', $this->redditnick, "- ----------------------");
                        $this->out_irc(null, '372', $this->redditnick, sprintf("-     %5d total users", count($this->users)));
                        $this->out_irc(null, '376', $this->redditnick, "End of MOTD");
                        break;

                    case 'JOIN':
                        $channels = split(',', $args[0]);
                        foreach ($channels as $chan) {
                            // Don't actually do anything
                        }
                        break;

                    case 'PART':
                        $channels = split(',', $args[0]);
                        foreach ($channels as $chan) {
                            // Don't actually do anything
                        }
                        break;

                    case 'PRIVMSG':
                        $channel = $args[0];
                        $str = $args[1];

                        if (strpos($str, "\001ACTION") === 0) {
                            $str = strtr($str, array(
                                "\001ACTION" => '',
                                "\001" => ''
                            ));
                            $action = 1;
                        }
                        else {
                            $action = 0;
                        }

                        if (in_array($channel, $this->prefixes)) {
                            foreach ($this->prefixes as $prefix => $ccode) {
                                if ($channel == $ccode) {
                                    $str = "{$prefix} {$str}";
                                    break;
                                }
                            }
                        }
                        if ( $action )
                            $str = '/me '.$str;

                        $this->last_message = $str;

                        $this->out_robin('message', $str);
                        break;

                    case 'WHOIS':
                        $users = split(',', $args[0]);
                        foreach ($users as $user) {
                            $userkey = strtoupper($user);
                            if ( array_key_exists($userkey, $this->users) ) {
                                $user = $this->users[$userkey];
                                $userstr = $user['name'];
                                $this->out_irc(null, '311', array($this->redditnick, $userstr, $userstr, self::ROBIN_HOST, "*"), $user['vote']);
                                if ( !$user['present'] )
                                    $this->out_irc(null, '301', array($this->redditnick, $userstr), "not present");
                                $this->out_irc(null, '318', array($this->redditnick, $userstr), "End of /WHOIS");
                            }
                            else {
                                $this->out_irc(null, '401', array($this->redditnick, $user), "No such user");
                            }
                        }
                        break;

                    case 'WHOWAS':
                        $users = split(',', $args[0]);
                        foreach ($users as $user) {
                            $this->out_irc(null, '406', array($this->redditnick, $user), "Was no such user");
                        }
                        break;

                    case 'PING':
                        $this->out_irc(null, 'PONG', self::ROBIN_HOST, $args[0]);
                        break;

                    case 'QUIT':
                        fclose($this->listen_sockets[self::IRC_SOCK]);
                        $this->redditws->close();
                        $this->listen_sockets = array();
                        break;
                }
                break;

            case 'ROBIN':
                $payload = $args[0];
                switch (strtoupper($msg)) {
                    case 'JOIN':
                    case 'PART':
                        $userkey = strtoupper($payload['user']);
                        if ( !array_key_exists($userkey, $this->users) ) {
                            $this->debug('WARN', "No such user: " . $userkey);
                            $this->users[$userkey]['present'] = array('vote' => "NOVOTE", 'name' => $payload['user'], 'present' => false);
                        }
                        $this->users[$userkey]['present'] = strtoupper($msg) == 'JOIN';
                        // Maintain login by periodically refreshing
                        if (time() > ($this->robin_last_load_time + self::ROBIN_TIMEOUT)) {
                            $this->refresh();
                        }
                        break;
                    case 'CHAT':
                        if (
                            $payload['from'] == $this->redditnick &&
                            $payload['body'] == $this->last_message
                        ) {
                            // Do nothing if we just sent this message
                        } else {
                            $body = $payload['body'];
                            if (strpos($body, '/me ') === 0) {
                                $body = substr($body, strlen('/me '));
                                $action = 1;
                            }
                            else {
                                $action = 0;
                            }
                            list($channel, $body) = $this->filter_channel($body);
                            $body = $this->filter_body($body);
                            if ($body) {
                                if ( $action )
                                    $body = "\001ACTION {$body}\001";
                                $this->out_irc($payload['from'], 'PRIVMSG', $channel, $body);
                            }
                        }
                        break;
                    case 'VOTE':
                        $userkey = strtoupper($payload['from']);
                        if ( array_key_exists($userkey, $this->users) ) {
                            $this->users[$userkey]['vote'] = $payload['vote'];
                        }
                        else {
                            $this->debug('WARN', "No such user: " . $userkey);
                        }
                        $this->out_irc($payload['from'], 'PRIVMSG', self::IRC_CHANNEL, "\001ACTION voted to {$payload['vote']}\001");
                        //$this->out_irc($payload['from'], 'NOTICE', self::IRC_CHANNEL, "voted to {$payload['vote']}");
                        break;
                    case 'PLEASE_VOTE':
                        $this->out_irc(null, 'NOTICE', self::IRC_CHANNEL, 'Polls are closing soon, please vote');
                        break;
                    case 'NO_MATCH':
                        $this->out_irc(null, 'NOTICE', self::IRC_CHANNEL, 'no compatible room found for matching, we will count votes and check again for a match in 1 minute.');
                        break;
                    case 'SYSTEM_BROADCAST':
                        $this->out_irc(null, 'NOTICE', self::IRC_CHANNEL, $payload['body']);
                        break;
                    case 'MERGE':
                        // We need the new websocket URL
                        $this->redditws->close();
                        $this->refresh();
                        break;
                    case 'USERS_ABANDONED':
                        // users_abandoned {"users":["Atheron3"]} (? -> 1724)
                        $this->out_irc(null, 'NOTICE', '#dev', $msg . ' ' . json_encode($payload));
                        break;
                }
                break;
        }
    }

    protected function filter_body($body) {
        foreach ($this->body_filters as $filter) {
            if (preg_match($filter, $body)) {
                return false;
            }
        }
        return $body;
    }

    protected function filter_channel($body) {
        $channel = self::IRC_CHANNEL;
        foreach ($this->prefixes as $prefix => $ccode) {
            if (strpos($body, $prefix) === 0) {
                $channel = $ccode;
                $body = trim(substr($body, strlen($prefix)));
                break;
            }
        }

        return array($channel, $body);
    }

    public function tally() {
        $tally = array();
        foreach ($this->users as $k => $user) {
            if ( array_key_exists($user['vote'], $tally) )
                $tally[$user['vote']]++;
            else
                $tally[$user['vote']] = 1;
        }
        return $tally;
    }

    protected function debug($type, $str) {
        if (self::DEBUG) {
            fprintf(STDERR, "[%s][%s] %s\n", date('YmdHis'), $type, $str);
        }
    }
}

$ri = new Robin_IRC();
