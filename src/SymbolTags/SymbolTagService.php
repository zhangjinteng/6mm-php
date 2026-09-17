<?php

declare(strict_types=1);

namespace SixMm\Shared\SymbolTags;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final class SymbolTagService
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tagTable = 'symbol_tag',
        private string $relationTable = 'symbol_config_tag',
        private string $relationTagColumn = 'symbol_tag_id',
        private string $relationSymbolColumn = 'symbol_config_id',
        private ?int $ownerId = null,
        private string $ownerColumn = 'agent_id'
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $query = $this->connection->table($this->tagTable)->whereNull('deleted_at');
        $this->applyOwnerScope($query);

        return $query
            ->orderBy('parent_id')
            ->orderByDesc('sort')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'tag_name', 'tag_name_zh', 'tag_name_en', 'tag_code'])
            ->map(fn (object $row): array => $this->serialize($row))
            ->values()
            ->all();
    }

    /** @return array{lists: list<array<string, mixed>>, count: int, parent_count: int} */
    public function search(SymbolTagQuery $criteria): array
    {
        $relationCounts = $this->connection->table($this->relationTable)
            ->select($this->relationTagColumn . ' as relation_tag_id')
            ->selectRaw('COUNT(DISTINCT ' . $this->relationSymbolColumn . ') AS symbol_count')
            ->groupBy($this->relationTagColumn);
        $this->applyOwnerScope($relationCounts);

        $query = $this->connection->table($this->tagTable . ' as st')
            ->leftJoinSub($relationCounts, 'relations', static function ($join): void {
                $join->on('relations.relation_tag_id', '=', 'st.id');
            })
            ->whereNull('st.deleted_at');
        $this->applyOwnerScope($query, 'st');

        if ($criteria->keyword() !== '') {
            $like = '%' . strtolower($this->escapeLike($criteria->keyword())) . '%';
            $query->where(static function (Builder $nested) use ($like): void {
                $nested->whereRaw('LOWER(st.tag_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(st.tag_name_zh) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(st.tag_name_en) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(st.tag_code) LIKE ?', [$like]);
            });
        }

        if ($criteria->parentId() !== null) {
            $parentId = $criteria->parentId();
            if ($parentId === 0) {
                $rootIds = $this->connection->table($this->tagTable)
                    ->where('parent_id', 0)
                    ->whereNull('deleted_at');
                $this->applyOwnerScope($rootIds);
                $rootIds = $rootIds->pluck('id');
                $query->where(static function (Builder $nested) use ($rootIds): void {
                    $nested->where('st.parent_id', 0);
                    if ($rootIds->isNotEmpty()) {
                        $nested->orWhereIn('st.parent_id', $rootIds->all());
                    }
                });
            } else {
                $query->where(static function (Builder $nested) use ($parentId): void {
                    $nested->where('st.id', $parentId)->orWhere('st.parent_id', $parentId);
                });
            }
        }

        if ($criteria->enabled() !== null) {
            $query->where('st.is_enable', $criteria->enabled());
        }

        $rows = $query
            ->orderByRaw('CASE WHEN st.parent_id = 0 THEN st.id ELSE st.parent_id END ASC')
            ->orderByRaw('CASE WHEN st.parent_id = 0 THEN 0 ELSE 1 END ASC')
            ->orderByDesc('st.sort')
            ->orderBy('st.id')
            ->get([
                'st.id', 'st.parent_id', 'st.tag_name', 'st.tag_name_zh',
                'st.tag_name_en', 'st.tag_code', 'st.sort', 'st.is_enable',
                'st.created_at', 'st.updated_at',
                $this->connection->raw('COALESCE(relations.symbol_count, 0) AS symbol_count'),
            ])
            ->map(fn (object $row): array => $this->serialize($row, true))
            ->values()
            ->all();

        return [
            'lists' => $rows,
            'count' => count($rows),
            'parent_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => $row['parent_id'] === 0
            )),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw SymbolTagException::because(SymbolTagException::NOT_FOUND);
        }

        return $this->serialize($row, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        return $this->persist($this->normalize($input));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(int $id, array $input): array
    {
        if ($id <= 0) {
            throw SymbolTagException::because(SymbolTagException::INVALID_PARAMETERS);
        }

        return $this->persist($this->normalize($input), $id);
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw SymbolTagException::because(SymbolTagException::INVALID_PARAMETERS);
        }

        $this->connection->transaction(function () use ($id): void {
            $tag = $this->connection->table($this->tagTable)
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->lockForUpdate();
            $this->applyOwnerScope($tag);
            $tag = $tag->first();
            if ($tag === null) {
                throw SymbolTagException::because(SymbolTagException::NOT_FOUND);
            }
            $children = $this->connection->table($this->tagTable)
                ->where('parent_id', $id)
                ->whereNull('deleted_at');
            $this->applyOwnerScope($children);
            if ($children->exists()) {
                throw SymbolTagException::because(SymbolTagException::HAS_CHILDREN);
            }
            $relations = $this->connection->table($this->relationTable)
                ->where($this->relationTagColumn, $id);
            $this->applyOwnerScope($relations);
            if ($relations->exists()) {
                throw SymbolTagException::because(SymbolTagException::IN_USE);
            }

            $now = $this->timestamp();
            $delete = $this->connection->table($this->tagTable)->where('id', $id);
            $this->applyOwnerScope($delete);
            $delete->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function persist(array $data, ?int $id = null): array
    {
        return $this->connection->transaction(function () use ($data, $id): array {
            if ($id !== null && $this->find($id, true) === null) {
                throw SymbolTagException::because(SymbolTagException::NOT_FOUND);
            }

            $parentId = (int) $data['parent_id'];
            if ($parentId !== 0) {
                if ($id !== null && $parentId === $id) {
                    throw SymbolTagException::because(SymbolTagException::INVALID_PARENT);
                }
                $parent = $this->connection->table($this->tagTable)
                    ->where('id', $parentId)
                    ->where('parent_id', 0)
                    ->whereNull('deleted_at');
                $this->applyOwnerScope($parent);
                $parent = $parent->first();
                if ($parent === null) {
                    throw SymbolTagException::because(SymbolTagException::INVALID_PARENT);
                }
                if ($id !== null) {
                    $children = $this->connection->table($this->tagTable)
                        ->where('parent_id', $id)
                        ->whereNull('deleted_at');
                    $this->applyOwnerScope($children);
                    if ($children->exists()) {
                        throw SymbolTagException::because(SymbolTagException::PARENT_WITH_CHILDREN);
                    }
                }
            }

            $duplicate = $this->connection->table($this->tagTable)
                ->whereRaw('LOWER(tag_code) = ?', [$data['tag_code']])
                ->whereNull('deleted_at');
            $this->applyOwnerScope($duplicate);
            if ($id !== null) {
                $duplicate->where('id', '<>', $id);
            }
            if ($duplicate->exists()) {
                throw SymbolTagException::because(SymbolTagException::CODE_EXISTS);
            }

            $now = $this->timestamp();
            $values = $data + ['updated_at' => $now];
            if ($id === null) {
                if ($this->ownerId !== null) {
                    $values[$this->ownerColumn] = $this->ownerId;
                }
                $id = (int) $this->connection->table($this->tagTable)
                    ->insertGetId($values + ['created_at' => $now]);
            } else {
                $update = $this->connection->table($this->tagTable)->where('id', $id);
                $this->applyOwnerScope($update);
                $update->update($values);
            }

            if ((int) $data['is_enable'] === 0) {
                $children = $this->connection->table($this->tagTable)
                    ->where('parent_id', $id)
                    ->whereNull('deleted_at')
                    ->where('is_enable', '<>', 0);
                $this->applyOwnerScope($children);
                $children->update(['is_enable' => 0, 'updated_at' => $now]);
            }

            $row = $this->find($id);
            if ($row === null) {
                throw SymbolTagException::because(SymbolTagException::NOT_FOUND);
            }

            return $this->serialize($row, true);
        });
    }

    /** @param array<string, mixed> $input @return array<string, int|string> */
    private function normalize(array $input): array
    {
        $parentId = filter_var($input['parent_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $sort = filter_var($input['sort'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $enabled = filter_var($input['is_enable'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($input['tag_name'] ?? ''));
        $code = strtolower(trim((string) ($input['tag_code'] ?? '')));
        $nameZh = trim((string) ($input['tag_name_zh'] ?? ''));
        $nameEn = trim((string) ($input['tag_name_en'] ?? ''));

        if ($parentId === false || $sort === false || !in_array($enabled, [0, 1], true)
            || $name === '' || $this->textLength($name) > 100
            || !preg_match('/^[a-z0-9_]{1,64}$/', $code)
            || $this->textLength($nameZh) > 100 || $this->textLength($nameEn) > 100) {
            throw SymbolTagException::because(SymbolTagException::INVALID_PARAMETERS);
        }

        return [
            'parent_id' => $parentId,
            'tag_name' => $name,
            'tag_code' => $code,
            'tag_name_zh' => $nameZh !== '' ? $nameZh : $name,
            'tag_name_en' => $nameEn,
            'sort' => $sort,
            'is_enable' => $enabled,
        ];
    }

    private function find(int $id, bool $lock = false): ?object
    {
        $query = $this->connection->table($this->tagTable)
            ->where('id', $id)
            ->whereNull('deleted_at');
        $this->applyOwnerScope($query);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return array<string, mixed> */
    private function serialize(object $row, bool $includeMeta = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'parent_id' => (int) $row->parent_id,
            'tag_name' => (string) $row->tag_name,
            'tag_code' => (string) $row->tag_code,
            'tag_name_zh' => (string) ($row->tag_name_zh ?? ''),
            'tag_name_en' => (string) ($row->tag_name_en ?? ''),
        ];
        if ($includeMeta) {
            $data += [
                'sort' => (int) ($row->sort ?? 0),
                'is_enable' => (int) ($row->is_enable ?? 0),
                'symbol_count' => (int) ($row->symbol_count ?? 0),
                'created_at' => (string) ($row->created_at ?? ''),
                'updated_at' => (string) ($row->updated_at ?? ''),
            ];
        }

        return $data;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    private function textLength(string $value): int
    {
        $length = grapheme_strlen($value);

        return $length === false ? strlen($value) : $length;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function applyOwnerScope(Builder $query, ?string $alias = null): void
    {
        if ($this->ownerId === null) {
            return;
        }

        $column = ($alias !== null ? $alias . '.' : '') . $this->ownerColumn;
        $query->where($column, $this->ownerId);
    }
}
