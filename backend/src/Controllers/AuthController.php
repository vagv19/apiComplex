<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\AuthorizationService;
use App\Services\UserAccessService;

class AuthController
{
    public function login(Request $request, array $params): void
    {
        $data = $request->body;
        $errors = Validator::validate($data, [
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if ($errors !== []) {
            Response::json(422, false, null, 'Validación fallida.', $errors);
            return;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM users
             WHERE email = :email AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
        );
        $stmt->execute([':email' => $data['email']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify((string) ($data['password'] ?? ''), (string) $user['password_hash'])) {
            Response::json(401, false, null, 'Credenciales inválidas.');
            return;
        }

        $token = signToken([
            'sub' => (string) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'ver' => (int) ($user['auth_version'] ?? 0),
            'iat' => time(),
            'exp' => time() + (int) config('JWT_TTL', 3600),
            'iss' => (string) config('JWT_ISSUER', 'football-tournament-api'),
        ]);

        $session = (new UserAccessService())->sessionData((int) $user['id']);
        Response::json(200, true, ['token' => $token, 'user' => $session], 'Autenticación correcta.');
    }

    public function me(Request $request, array $params): void
    {
        $user = AuthorizationService::requireCapability($request->headers, 'users.session.read');
        Response::json(
            200,
            true,
            (new UserAccessService())->sessionData((int) $user['sub']),
            'Sesión consultada.',
        );
    }
}
