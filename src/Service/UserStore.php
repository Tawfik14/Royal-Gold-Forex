<?php

namespace App\Service;

class UserStore
{
    private string $dataDir;

    public function __construct(string $projectDir)
    {
        $this->dataDir = rtrim($projectDir, '/').'/var/data';
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0777, true);
        }
    }

    private function getUsersPath(): string
    {
        return $this->dataDir.'/users.json';
    }

    private function loadUsers(): array
    {
        $p = $this->getUsersPath();
        if (!is_file($p)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($p), true);
        return is_array($raw) ? $raw : [];
    }

    private function saveUsers(array $users): void
    {
        file_put_contents($this->getUsersPath(), json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function findByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        foreach ($this->loadUsers() as $u) {
            if (mb_strtolower($u['email']) === $email) {
                return $u;
            }
        }
        return null;
    }

    public function createUser(string $firstName, string $name, string $email, int $age, string $passwordPlain): array
    {
        $users = $this->loadUsers();
        if ($this->findByEmail($email)) {
            throw new \RuntimeException('Un compte existe déjà avec cet email.');
        }
        $role = $this->inferRole($email);
        $user = [
            'id'         => bin2hex(random_bytes(8)),
            'firstName'  => $firstName,
            'name'       => $name,
            'email'      => $email,
            'age'        => $age,
            'password'   => password_hash($passwordPlain, PASSWORD_DEFAULT),
            'role'       => $role,
            'createdAt'  => date('c'),
        ];
        $users[] = $user;
        $this->saveUsers($users);
        return $user;
    }

    public function verify(string $email, string $passwordPlain): ?array
    {
        $u = $this->findByEmail($email);
        if (!$u) {
            return null;
        }
        if (!password_verify($passwordPlain, $u['password'])) {
            return null;
        }
        return $u;
    }

    private function inferRole(string $email): string
    {
        $adminEmails = [
            'admin@example.com',
            'admin@exchange.local',
        ];
        return in_array(mb_strtolower($email), array_map('mb_strtolower', $adminEmails), true) ? 'admin' : 'user';
    }
}

