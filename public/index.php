<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/database.php';

$db = new DB();
$pdo = $db->getPDO();

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Helper to get JSON body
function getJson(Request $request) {
    $body = $request->getBody()->getContents();
    return json_decode($body, true);
}

// ---------------------------
// Create a user (if doesn't exist)
$app->post('/users', function(Request $request, Response $response) use ($pdo){
    $data = getJson($request);
    if(empty($data['username'])) {
        $response->getBody()->write(json_encode(['error'=>'Username required']));
        return $response->withHeader('Content-Type','application/json')->withStatus(400);
    }
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (username) VALUES (:username)");
    $stmt->execute(['username'=>$data['username']]);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute(['username'=>$data['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode($user));
    return $response->withHeader('Content-Type','application/json');
});

// ---------------------------
// List all groups
$app->get('/groups', function(Request $request, Response $response) use ($pdo){
    $stmt = $pdo->query("SELECT * FROM groups");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode($groups));
    return $response->withHeader('Content-Type','application/json');
});

// Create a group
$app->post('/groups', function(Request $request, Response $response) use ($pdo){
    $data = getJson($request);
    if(empty($data['name'])) {
        $response->getBody()->write(json_encode(['error'=>'Group name required']));
        return $response->withHeader('Content-Type','application/json')->withStatus(400);
    }
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO groups (name) VALUES (:name)");
    $stmt->execute(['name'=>$data['name']]);
    $stmt = $pdo->prepare("SELECT * FROM groups WHERE name = :name");
    $stmt->execute(['name'=>$data['name']]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode($group));
    return $response->withHeader('Content-Type','application/json');
});

// Join a group
$app->post('/groups/{group_id}/join', function(Request $request, Response $response, array $args) use ($pdo){
    $data = getJson($request);
    if(empty($data['username'])) {
        $response->getBody()->write(json_encode(['error'=>'Username required']));
        return $response->withHeader('Content-Type','application/json')->withStatus(400);
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username=:username");
    $stmt->execute(['username'=>$data['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$user) return $response->withStatus(404)->withHeader('Content-Type','application/json')->write(json_encode(['error'=>'User not found']));

    $stmt = $pdo->prepare("INSERT OR IGNORE INTO group_users (user_id, group_id) VALUES (:user_id, :group_id)");
    $stmt->execute(['user_id'=>$user['id'], 'group_id'=>$args['group_id']]);

    $response->getBody()->write(json_encode(['status'=>'joined']));
    return $response->withHeader('Content-Type','application/json');
});

// Send a message to a group
$app->post('/groups/{group_id}/messages', function(Request $request, Response $response, array $args) use ($pdo){
    $data = getJson($request);
    if(empty($data['username']) || empty($data['message'])) {
        $response->getBody()->write(json_encode(['error'=>'Username and message required']));
        return $response->withHeader('Content-Type','application/json')->withStatus(400);
    }

    // Get user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username=:username");
    $stmt->execute(['username'=>$data['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$user) return $response->withStatus(404)->withHeader('Content-Type','application/json')->write(json_encode(['error'=>'User not found']));

    // Ensure user is in group
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO group_users (user_id, group_id) VALUES (:user_id, :group_id)");
    $stmt->execute(['user_id'=>$user['id'], 'group_id'=>$args['group_id']]);

    // Insert message
    $stmt = $pdo->prepare("INSERT INTO messages (group_id, user_id, message) VALUES (:group_id, :user_id, :message)");
    $stmt->execute(['group_id'=>$args['group_id'], 'user_id'=>$user['id'], 'message'=>$data['message']]);

    $response->getBody()->write(json_encode(['status'=>'message sent']));
    return $response->withHeader('Content-Type','application/json');
});

// List messages in a group
$app->get('/groups/{group_id}/messages', function(Request $request, Response $response, array $args) use ($pdo){
    $stmt = $pdo->prepare("
        SELECT m.id, u.username, m.message, m.created_at
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.group_id = :group_id
        ORDER BY m.created_at ASC
    ");
    $stmt->execute(['group_id'=>$args['group_id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode($messages));
    return $response->withHeader('Content-Type','application/json');
});

$app->run();
