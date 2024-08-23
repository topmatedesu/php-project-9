<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use Carbon\Carbon;
use Valitron\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use DiDom\Document;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('pdo', function () {
    $databaseUrl = parse_url(getenv('DATABASE_URL'));
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

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'index.phtml');
})->setName('main');

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');
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
    return $this->get('renderer')->render($response, "urls.phtml", $params);
})->setName('urls');

$app->get('/urls/{id}', function ($request, $response, array $args) {
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
    $pdo = $this->get('pdo');
    $query = 'SELECT * FROM urls WHERE id = ?';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    $urlSelect = $stmt->fetch();

    if (count($urlSelect) === 0) {
        return $response->write('Страница не найдена!')
            ->withStatus(404);
    }

    $queryCheck = 'SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC';
    $stmt = $pdo->prepare($queryCheck);
    $stmt->execute([$id]);
    $selectedCheck = $stmt->fetchAll();

    $params = [
        'flash' => $messages,
        'data' => $urlSelect,
        'alert' => $alert,
        'checkData' => $selectedCheck
    ];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('url');

$app->post('/urls', function ($request, $response) use ($router) {
    $urlData = $request->getParsedBodyParam('url');
    $validator = new Validator($urlData);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('Некорректный URL');

    if (!$validator->validate()) {
        $errors = $validator->errors();
        $params = [
            'url' => $urlData['name'],
            'errors' => $errors,
            'invalidForm' => 'is-invalid'
        ];
        $response = $response->withStatus(422);
        return $this->get('renderer')->render($response, 'index.phtml', $params);
    }

    $url = strtolower($urlData['name']);
    $parseUrl = parse_url($url);
    $urlName = "{$parseUrl['scheme']}://{$parseUrl['host']}";

    $pdo = $this->get('pdo');
    $queryUrl = 'SELECT name FROM urls WHERE name = ?';
    $stmt = $pdo->prepare($queryUrl);
    $stmt->execute([$urlName]);
    $selectedUrl = $stmt->fetchAll();

    if ($selectedUrl) {
        $queryId = 'SELECT id FROM urls WHERE name = ?';
        $stmt = $pdo->prepare($queryId);
        $stmt->execute([$urlName]);
        $selectId = (string) $stmt->fetchColumn();
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($router->urlFor('url', ['id' => $selectId]));
    }

    $createdAt = Carbon::now();
    $sql = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$urlName, $createdAt]);
    $lastInsertId = (string) $pdo->lastInsertId();
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withRedirect($router->urlFor('url', ['id' => $lastInsertId]));
});

$app->post('/urls/{url_id}/checks', function ($request, $response, array $args) use ($router) {
    $id = $args['url_id'];

    try {
        $pdo = $this->get('pdo');
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
            return $response->withRedirect($router->urlFor('url', ['id' => $id]));
        }
        $statusCode = !is_null($res) ? $res->getStatusCode() : null;

        $bodyHtml = $res->getBody();
        $document = new Document((string) $bodyHtml);
        $h1 = optional($document->first('h1'))->text();
        $title = optional($document->first('title'))->text();
        $description = optional($document->first('meta[name="description"]'))->getAttribute('content');

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

    return $response->withRedirect($router->urlFor('url', ['id' => $id]));
});

$app->run();
