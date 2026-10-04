<?php

declare(strict_types=1);

// Router for the `php -S` server started by TestHttpServer.

$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

switch ($path) {
    case '/hello':
        header('X-Greeting: hi');
        echo "hello world\n";

        break;

    case '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'headers' => getallheaders(),
            'body' => file_get_contents('php://input'),
        ]);

        break;

    case '/redirect':
        header('Location: '.$query['to'], true, (int) ($query['status'] ?? 302));
        echo "redirecting\n";

        break;

    case '/loop':
        header('Location: /loop', true, 302);

        break;

    case '/created':
        header('Location: http://denied.example/', true, 201);
        echo "created\n";

        break;

    case '/hit':
        // Lets tests prove whether a request actually reached the server.
        touch($query['file']);
        echo "hit\n";

        break;

    case '/large':
        echo str_repeat('x', (int) $query['size']);

        break;

    case '/status':
        http_response_code((int) $query['code']);
        echo 'status '.$query['code']."\n";

        break;

    default:
        http_response_code(404);
        echo "not found\n";
}
