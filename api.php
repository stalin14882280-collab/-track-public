<?php
error_reporting(0);
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');

$dataFile = __DIR__ . '/data.json';
$uploadsDir = __DIR__ . '/uploads/';
$avatarsDir = __DIR__ . '/avatars/';

if (!file_exists($uploadsDir)) mkdir($uploadsDir, 0777, true);
if (!file_exists($avatarsDir)) mkdir($avatarsDir, 0777, true);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit();

$action = $_GET['action'] ?? '';

// ========== ПОЛУЧИТЬ ВСЕ ДАННЫЕ ==========
if ($action === 'get') {
    if (file_exists($dataFile)) echo file_get_contents($dataFile);
    else echo json_encode(['tracks'=>[], 'likedTracks'=>[], 'playedTracks'=>[], 'currentUser'=>null, 'playlists'=>[], 'users'=>[]]);
    exit();
}

// ========== СОХРАНИТЬ ВСЕ ДАННЫЕ ==========
if ($action === 'save') {
    $input = file_get_contents('php://input');
    file_put_contents($dataFile, $input);
    echo json_encode(['ok'=>true]);
    exit();
}

// ========== ЗАГРУЗИТЬ ТРЕК ==========
if ($action === 'upload') {
    $title = $_POST['title'] ?? '';
    $artist = $_POST['artist'] ?? '';
    $genre = $_POST['genre'] ?? '';
    $userId = $_POST['userId'] ?? '';
    $trackId = time() . rand(100,999);
    $audioUrl = ''; $coverUrl = '';
    if ($_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION);
        $name = $trackId . '.' . $ext;
        move_uploaded_file($_FILES['audio']['tmp_name'], $uploadsDir . $name);
        $audioUrl = '/uploads/' . $name;
    }
    if ($_FILES['cover']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION);
        $name = $trackId . '_cover.' . $ext;
        move_uploaded_file($_FILES['cover']['tmp_name'], $uploadsDir . $name);
        $coverUrl = '/uploads/' . $name;
    }
    $newTrack = [
        'id' => $trackId,
        'title' => $title,
        'artist' => $artist,
        'genre' => $genre,
        'userId' => $userId,
        'status' => 'pending',
        'likes' => 0,
        'plays' => 0,
        'audioUrl' => $audioUrl,
        'coverUrl' => $coverUrl,
        'uploadDate' => date('c')
    ];
    $data = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!isset($data['tracks'])) $data['tracks'] = [];
    $data['tracks'][] = $newTrack;
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode($newTrack);
    exit();
}

// ========== УДАЛИТЬ ТРЕК ==========
if ($action === 'delete_track') {
    $input = json_decode(file_get_contents('php://input'), true);
    $trackId = $input['trackId'] ?? '';
    if ($trackId && file_exists($dataFile)) {
        $data = json_decode(file_get_contents($dataFile), true);
        $newTracks = [];
        foreach ($data['tracks'] as $t) {
            if ($t['id'] !== $trackId) $newTracks[] = $t;
            else {
                if (!empty($t['audioUrl'])) @unlink(__DIR__ . $t['audioUrl']);
                if (!empty($t['coverUrl'])) @unlink(__DIR__ . $t['coverUrl']);
            }
        }
        $data['tracks'] = $newTracks;
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        echo json_encode(['ok'=>true]);
    } else echo json_encode(['error'=>'not found']);
    exit();
}

// ========== ЗАГРУЗИТЬ АВАТАР ==========
if ($action === 'upload_avatar') {
    $userId = $_POST['userId'] ?? '';
    $avatarFile = $_FILES['avatar'] ?? null;
    if ($avatarFile && $avatarFile['error'] === UPLOAD_ERR_OK && $userId) {
        $ext = pathinfo($avatarFile['name'], PATHINFO_EXTENSION);
        $name = 'avatar_' . $userId . '.' . $ext;
        move_uploaded_file($avatarFile['tmp_name'], $avatarsDir . $name);
        $avatarUrl = '/avatars/' . $name;
        // обновляем в данных
        $data = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
        if ($data['currentUser'] && $data['currentUser']['username'] === $userId) {
            $data['currentUser']['avatarUrl'] = $avatarUrl;
            file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
        }
        echo json_encode(['success'=>true, 'avatarUrl'=>$avatarUrl]);
        exit();
    }
    echo json_encode(['error'=>'upload failed']);
    exit();
}

// ========== РЕГИСТРАЦИЯ ==========
if ($action === 'register') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    if (!$username || !$password) {
        echo json_encode(['error' => 'Заполните все поля']);
        exit();
    }
    $data = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    if (!isset($data['users'])) $data['users'] = [];
    foreach ($data['users'] as $u) {
        if ($u['username'] === $username) {
            echo json_encode(['error' => 'Пользователь уже существует']);
            exit();
        }
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $data['users'][] = ['username' => $username, 'password_hash' => $hash];
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'user' => ['username' => $username]]);
    exit();
}

// ========== ВХОД ==========
if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    if (!$username || !$password) {
        echo json_encode(['error' => 'Заполните все поля']);
        exit();
    }
    $data = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];
    $users = $data['users'] ?? [];
    $found = null;
    foreach ($users as $u) {
        if ($u['username'] === $username) {
            $found = $u;
            break;
        }
    }
    if (!$found || !password_verify($password, $found['password_hash'])) {
        echo json_encode(['error' => 'Неверный логин или пароль']);
        exit();
    }
    echo json_encode(['success' => true, 'user' => ['username' => $username]]);
    exit();
}
?>
