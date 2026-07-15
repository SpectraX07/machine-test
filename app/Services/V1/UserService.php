<?php

namespace App\Services\V1;

use App\Exceptions\BadRequestException;
use App\Exceptions\NotFoundException;
use App\Models\V1\User as UserModel;
use RuntimeException;

class UserService
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE     = 100;

    protected UserModel $userModel;

    public function __construct(?UserModel $userModel = null)
    {
        $this->userModel = $userModel ?? new UserModel();
    }

    public function register(array $data): object
    {
        if (! $this->userModel->insert($data)) {
            throw new RuntimeException('Unable to register user.');
        }

        $user = $this->userModel->find($this->userModel->getInsertID());

        return $this->userModel->toPublic($user);
    }

    public function find(int $id): object
    {
        $user = $this->userModel->find($id);

        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        return $this->userModel->toPublic($user);
    }

    /**
     * Cursor/keyset pagination for large tables.
     * Pass ?cursor=<last_id>&per_page=25 — never uses OFFSET.
     */
    public function listUsers(?int $cursor = null, ?int $perPage = null): array
    {
        $perPage = $this->normalizePerPage($perPage);

        $rows = $this->userModel->paginateByCursor($cursor, $perPage);
        $hasMore = count($rows) > $perPage;

        if ($hasMore) {
            array_pop($rows);
        }

        $items = array_map(static fn ($user) => $user, $rows);
        $nextCursor = $hasMore && $items !== []
            ? (int) end($items)->id
            : null;

        return [
            'items'      => $items,
            'pagination' => [
                'per_page'    => $perPage,
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
            ],
        ];
    }

    public function update(int $id, array $data): object
    {
        $user = $this->userModel->find($id);

        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        $data = array_filter(
            $data,
            static fn ($value) => $value !== null && $value !== ''
        );

        if ($data === []) {
            throw new BadRequestException('No fields provided to update.');
        }

        if (! $this->userModel->update($id, $data)) {
            throw new RuntimeException('Unable to update user.');
        }

        return $this->userModel->toPublic($this->userModel->find($id));
    }

    public function delete(int $id): void
    {
        $user = $this->userModel->find($id);

        if (! $user) {
            throw new NotFoundException('User not found.');
        }

        if (! $this->userModel->delete($id)) {
            throw new RuntimeException('Unable to delete user.');
        }
    }

    private function normalizePerPage(?int $perPage): int
    {
        if ($perPage === null || $perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
