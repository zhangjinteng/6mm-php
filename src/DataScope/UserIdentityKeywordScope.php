<?php

namespace SixMm\Shared\DataScope;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

/** Literal name/nickname or exact public UID, applied before pagination. */
class UserIdentityKeywordScope implements UserDataScope
{
    public function __construct(
        private UserDataScope $base,
        private string $keyword,
        private string $userAlias = 'users'
    ) {}

    public function apply(Builder $query, string $agentColumn): void
    {
        $this->base->apply($query, $agentColumn);
        $keyword = trim($this->keyword);
        if ($keyword === '') return;

        $pattern = '%' . strtr(mb_strtolower($keyword, 'UTF-8'), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $username = $query->getGrammar()->wrap($this->userAlias . '.username');
        $nickname = $query->getGrammar()->wrap($this->userAlias . '.nick_name');
        $uid = $this->userAlias . '.public_user_id';
        $query->where(static function (Builder $nested) use ($keyword, $pattern, $username, $nickname, $uid): void {
            $nested->whereRaw("LOWER(COALESCE({$username}, '')) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(COALESCE({$nickname}, '')) LIKE ? ESCAPE '!'", [$pattern]);
            if (ctype_digit($keyword) && filter_var($keyword, FILTER_VALIDATE_INT) !== false) {
                $nested->orWhere($uid, $keyword);
            }
        });
    }
}
