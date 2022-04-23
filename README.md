# PHP WebSocket

Enable websockets with PHP to create a simple chat room.

## Start the websocket server:

```
php -q websocket.php [your-domain] 9600
```

Note:  You can replace port 9600 with any available port on your system.

To run the PHP script in the background on a Ubuntu server, use the following command:

```
nohup php websocket.php [your-domain] 9600 &
```

## For the client-side JavaScript portion:

[JavaScript chat room](https://github.com/orangeable/javascript-chat)
