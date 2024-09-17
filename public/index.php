<?php

require_once __DIR__ . '/../vendor/autoload.php';

use DI\Container;
use Slim\Flash\Messages;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Http\ServerRequest;
use Slim\Http\Response;
use Carbon\Carbon;
use Valitron\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use DiDom\Document;
use Dotenv\Dotenv;
use Spatie\Ignition\Ignition;
use Illuminate\Support\Optional;

Ignition::make()->setTheme('dark')->register();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$container = new Container();
$container->set('view', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new Messages();
});
$container->set('db', function () {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();

    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    if (!$databaseUrl) {
        throw new \Exception("Error reading database url");
    }

    $username = $databaseUrl['user'];
    $password = $databaseUrl['pass'];
    $host = $databaseUrl['host'];
    $port = $databaseUrl['port'];
    $dbName = ltrim($databaseUrl['path'], '/');

    $conStr = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
        $host,
        $port,
        $dbName,
        $username,
        $password
    );

    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    return $pdo;
});
$container->set('client', function () {
    return new Client();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function (ServerRequest $request, Response $response) {
    return $this->get('view')->render($response, 'main/index.phtml');
})->setName('main.index');

$app->get('/urls', function (ServerRequest $request, Response $response) {
    $pdo = $this->get('db');
    $queryUrls = 'SELECT
        urls.id AS id,
        urls.name AS name,
        MAX(url_checks.created_at) AS created_at,
        url_checks.status_code AS status_code
    FROM urls LEFT JOIN url_checks ON urls.id = url_checks.url_id
    GROUP BY urls.id, status_code
    ORDER BY created_at DESC';
    $stmt = $pdo->prepare($queryUrls);
    $stmt->execute();
    $selectedUrls = $stmt->fetchAll(\PDO::FETCH_UNIQUE);

    $params = ['data' => $selectedUrls];
    return $this->get('view')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function (ServerRequest $request, Response $response, array $args) {
    $messages = $this->get('flash')->getMessages();
    $alert = '';

    switch (key($messages)) {
        case 'success':
            $alert = 'success';
            break;
        case 'error':
            $alert = 'warning';
            break;
        case 'danger':
            $alert = 'danger';
            break;
    }

    $id = $args['id'];
    $pdo = $this->get('db');
    $query = 'SELECT * FROM urls WHERE id = ?';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    $selectedUrl = $stmt->fetch();

    if (!$selectedUrl) {
        return $response->write('Страница не найдена!')
            ->withStatus(404);
    }

    $queryCheck = 'SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC';
    $stmt = $pdo->prepare($queryCheck);
    $stmt->execute([$id]);
    $selectedCheck = $stmt->fetchAll();

    $params = [
        'flash' => $messages,
        'data' => $selectedUrl,
        'alert' => $alert,
        'checkData' => $selectedCheck
    ];
    return $this->get('view')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$app->post('/urls', function (ServerRequest $request, Response $response) use ($router) {
    $formData = (array) $request->getParsedBody();
    $validator = new Validator($formData['url']);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('Некорректный URL');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $params = [
            'url' => $formData['url'],
            'errors' => $errors,
            'invalidForm' => 'is-invalid'
        ];
        $response = $response->withStatus(422);
        return $this->get('view')->render($response, 'main/index.phtml', $params);
    }

    $parseUrl = parse_url(mb_strtolower($formData['url']['name']));
    $normalizedUrl = "{$parseUrl['scheme']}://{$parseUrl['host']}";

    $pdo = $this->get('db');
    $queryUrl = 'SELECT name FROM urls WHERE name = ?';
    $stmt = $pdo->prepare($queryUrl);
    $stmt->execute([$normalizedUrl]);
    $url = $stmt->fetchAll();

    if ($url) {
        $queryId = 'SELECT id FROM urls WHERE name = ?';
        $stmt = $pdo->prepare($queryId);
        $stmt->execute([$normalizedUrl]);
        $selectId = (string) $stmt->fetchColumn();
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($router->urlFor('urls.show', ['id' => $selectId]));
    }

    $createdAt = Carbon::now();
    $sql = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$normalizedUrl, $createdAt]);
    $lastInsertId = (string) $pdo->lastInsertId();
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withRedirect($router->urlFor('urls.show', ['id' => $lastInsertId]));
});

$app->post('/urls/{url_id:[0-9]+}/checks', function (
    ServerRequest $request,
    Response $response,
    array $args
) use ($router) {
    $id = $args['url_id'];

    try {
        $pdo = $this->get('db');
        $queryUrl = 'SELECT name FROM urls WHERE id = ?';
        $stmt = $pdo->prepare($queryUrl);
        $stmt->execute([$id]);
        $selectUrl = $stmt->fetch(\PDO::FETCH_COLUMN);
        $createdAt = Carbon::now();

        $client = $this->get('client');
        try {
            $res = $client->get($selectUrl);
            $this->get('flash')->addMessage('success', 'Страница успешно проверена');
        } catch (RequestException $e) {
            $res = $e->getResponse();
            $this->get('flash')->clearMessages();
            $errorMessage = 'Проверка была выполнена успешно, но сервер ответил c ошибкой';
            $this->get('flash')->addMessage('error', $errorMessage);
        } catch (ConnectException $e) {
            $errorMessage = 'Произошла ошибка при проверке, не удалось подключиться';
            $this->get('flash')->addMessage('danger', $errorMessage);
            return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
        }
        $statusCode = !is_null($res) ? $res->getStatusCode() : null;

        $bodyHtml = $res->getBody();
        $document = new Document((string) $bodyHtml);
        $h1 = (string) optional($document->first('h1'))->text();
        $title = (string) optional($document->first('title'))->text();
        $description = (string) optional($document->first('meta[name="description"]'))->getAttribute('content');

        $sql = "INSERT INTO url_checks (
            url_id,
            created_at,
            status_code,
            h1, 
            title, 
            description)
            VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id, $createdAt, $statusCode, $h1, $title, $description]);
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
});

$app->run();
