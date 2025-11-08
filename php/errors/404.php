<?php
// php/errors/404.php — Pàgina no trobada
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// URL de retorn a l'inici
$home = url('index.php');
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="ca">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pàgina no trobada — Kinosonik Riders</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background-color: #0d1117;
      color: #f0f0f0;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
      text-align: center;
    }
    h1 {
      font-size: 5rem;
      font-weight: 700;
      color: #ffcc00;
    }
    p {
      font-size: 1.25rem;
      margin-bottom: 2rem;
      color: #bbb;
    }
    a.btn-home {
      color: #fff;
      background-color: #198754;
      border: none;
      padding: 0.75rem 1.5rem;
      font-size: 1rem;
      border-radius: 0.5rem;
      text-decoration: none;
      transition: background-color 0.2s ease-in-out;
    }
    a.btn-home:hover {
      background-color: #157347;
    }
  </style>
</head>
<body>
  <main>
    <h1>404</h1>
    <p>La pàgina que busques no existeix o ha estat moguda.</p>
    <a href="<?= h($home) ?>" class="btn-home">Torna a l'inici</a>
  </main>
</body>
</html>