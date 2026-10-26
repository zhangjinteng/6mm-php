<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction;
use Illuminate\Database\Eloquent\Builder;
final class UserFilter {
    /** The caller supplies a pre-scoped query; all keyword OR branches remain nested. */
    public static function apply(array $input,Builder $query): array
    {
        if (!array_key_exists('public_user_id', $input) && !array_key_exists('user_name', $input) && !array_key_exists('user_keyword', $input)) { return $input; }
        // Public UIDs are positive bigint values; keep string comparison to avoid precision loss.
        if (isset($input['public_user_id'])) {
            $uid = $input['public_user_id'];
            if (!is_string($uid) || !preg_match('/^[1-9][0-9]*$/D', $uid) || strlen($uid) > 19 ||
                (strlen($uid) === 19 && strcmp($uid, '9223372036854775807') > 0)) {
                unset($input['public_user_id'], $input['user_name'], $input['user_keyword']);
                $input['user_filter'] = ['user_ids' => []];
                return $input;
            }
        }
        if (isset($input['public_user_id'])) { $query->where('public_user_id', $input['public_user_id']); }
        if (isset($input['user_name'])) {
            $literal = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input['user_name']) . '%';
            $query->whereRaw("username LIKE ? ESCAPE '!'", [$literal]);
        }
        if (isset($input['user_keyword'])) {
            $keyword = $input['user_keyword'];
            $literal = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($keyword, 'UTF-8')) . '%';
            $query->where(static function ($nested) use ($keyword, $literal): void {
                $nested->whereRaw("LOWER(COALESCE(username, '')) LIKE ? ESCAPE '!'", [$literal])
                    ->orWhereRaw("LOWER(COALESCE(nick_name, '')) LIKE ? ESCAPE '!'", [$literal]);
                if (self::validUid($keyword)) {
                    $nested->orWhere('public_user_id', $keyword);
                }
            });
        }
        $ids = $query->orderBy('user_id')->limit(501)->pluck('user_id')->map(static fn ($id) => (string) $id)->all();
        if (count($ids) > 500) {
            throw new ReadException('USER_FILTER_TOO_BROAD',422);
        }
        sort($ids, SORT_STRING);
        unset($input['public_user_id'], $input['user_name'], $input['user_keyword']);
        $input['user_filter'] = ['user_ids' => $ids];
        return $input;
    }

    private static function validUid(string $value): bool {
        return preg_match('/^[1-9][0-9]*$/D',$value)===1 && (strlen($value)<19 ||
            (strlen($value)===19 && strcmp($value,'9223372036854775807')<=0));
    }
}
