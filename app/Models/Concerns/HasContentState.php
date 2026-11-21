<?php

namespace App\Models\Concerns;

use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

/** Shared visible / moderated / deleted handling for threads and posts. */
trait HasContentState
{
    public function isVisible(): bool
    {
        return $this->state === 'visible';
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where($this->getTable().'.state', 'visible');
    }

    /** Content the current visitor may see inside the given node. */
    public function scopeVisibleIn(Builder $query, Node $node): Builder
    {
        $table = $this->getTable();
        $userId = auth()->id();
        $canApprove = forum_can('mod.approve', $node);
        $canViewDeleted = forum_can('mod.viewDeleted', $node);

        return $query->where(function (Builder $q) use ($table, $userId, $canApprove, $canViewDeleted) {
            $q->where("$table.state", 'visible');

            if ($canApprove) {
                $q->orWhere("$table.state", 'moderated');
            } elseif ($userId) {
                $q->orWhere(fn (Builder $own) => $own->where("$table.state", 'moderated')->where("$table.user_id", $userId));
            }

            if ($canViewDeleted) {
                $q->orWhere("$table.state", 'deleted');
            }
        });
    }
}
