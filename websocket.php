<?php

    // arguments (host and port):
    $host = $argv[1];
    $port = $argv[2];
    $null = NULL;

    // create socket:
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    socket_bind($socket, 0, $port);
    socket_listen($socket);

    // create listening socket:
    $clients = array($socket);

    // create endless loop so script doesn't stop:
    while (true) {
        // manage multiple connections:
        $changed = $clients;
        socket_select($changed, $null, $null, 0, 10);

        // check for new socket:
        if (in_array($socket, $changed)) {
            $socket_new = socket_accept($socket);
            $clients[] = $socket_new;

            $header = socket_read($socket_new, 1024);
            perform_handshaking($header, $socket_new, $host, $port);

            socket_getpeername($socket_new, $ip);
            $response = mask(json_encode(array("type" => "system", "message" => $ip . " connected")));
            send_message($response);

            $found_socket = array_search($socket, $changed);
            unset($changed[$found_socket]);
        }

        // loop through all connected sockets:
        foreach($changed as $changed_socket) {
            // check for incoming data:
            while(socket_recv($changed_socket, $buf, 1024, 0) >= 1) {
                if (substr($buf, 0, 1) == "{") {
                    $received_text = $buf;
                } else {
                    $received_text = unmask($buf);
                }
                $data = json_decode($received_text);

                if ($data) {
                    $response_text = mask(json_encode($data));
                    send_message($response_text);
                }
                break 2;
            }

            $buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);

            if ($buf === false) {
                // remove client from $clients array:
                $found_socket = array_search($changed_socket, $clients);
                socket_getpeername($changed_socket, $ip);
                unset($clients[$found_socket]);

                // notify all users about disconnected connection:
                $response = mask(json_encode(array("type" => "system", "message" => $ip . " disconnected")));
            }
        }
    }

    // close the listening socket:
    socket_close($socket);

    // send message:
    function send_message($message) {
        global $clients;

        foreach($clients as $changed_socket) {
            @socket_write($changed_socket, $message, strlen($message));
        }

        return true;
    }

    // unmask incoming framed message:
    function unmask($message) {
        $length = ord($message[1]) & 127;

        if ($length == 126) {
            $masks = substr($message, 4, 4);
            $data  = substr($message, 8);
        }
        elseif ($length == 127) {
            $masks = substr($message, 10, 4);
            $data  = substr($message, 14);
        }
        else {
            $masks = substr($message, 2, 4);
            $data  = substr($message, 6);
        }

        $message = "";

        for ($i = 0; $i < strlen($data); $i++) {
            $message .= $data[$i] ^ $masks[$i % 4];
        }

        return $message;
    }

    // encode message for transfer to client:
    function mask($message) {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($message);

        if ($length <= 125) {
            $header = pack("CC", $b1, $length);
        } elseif ($length > 125 && $length < 65536) {
            $header = pack("CCn", $b1, 126, $length);
        } elseif ($length >= 65536) {
            $header = pack ("CCNN", $b1, 127, $length);
        }

        return $header.$message;
    }

    // handshake new client:
    function perform_handshaking($received_header, $client_conn, $host, $port) {
        $headers  = array();
        $protocol = (stripos($host, "local.") !== false) ? "ws" : "wss";
        $lines    = preg_split("/\r\n/", $received_header);

        foreach ($lines as $line) {
            $line = chop($line);

            if (preg_match("/\A(\S+): (.*)\z/", $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $secKey = $headers["Sec-WebSocket-Key"];
        $secAccept = base64_encode(pack("H*", sha1($secKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11")));

        $upgrade =
            "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: WebSocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: $host\r\n" .
            "WebSocket-Location: $protocol://$host:$port/websocket.php\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";

        socket_write($client_conn, $upgrade, strlen($upgrade));
    }

?>