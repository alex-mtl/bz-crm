<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\Geo\Models\ApartmentNote;
use App\Domain\Geo\Models\House;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reads which note of an agitator (ФО §6.11): a personal note — its author and nobody else, whatever their
 * role; a note for the staff — its author and those who hold `geo.notes.team.read` for the house. Everything
 * that shows notes starts here.
 */
final readonly class FieldNotes
{
    public function __construct(private FieldAccess $access) {}

    /**
     * @return Builder<ApartmentNote>
     */
    public function visibleTo(User $viewer, House $house): Builder
    {
        $team = $this->access->can($viewer, 'geo.notes.team.read', $house);

        return ApartmentNote::query()->where('house_id', $house->id)
            ->where(function (Builder $where) use ($viewer, $team): void {
                $where->where('author_person_id', $viewer->person_id);
                if ($team) {
                    $where->orWhere('visibility', ApartmentNote::TEAM);
                }
            });
    }

    public function canRead(User $viewer, ApartmentNote $note): bool
    {
        return $note->author_person_id === $viewer->person_id
            || ($note->visibility === ApartmentNote::TEAM && $this->access->can($viewer, 'geo.notes.team.read', $note->apartment->house));
    }
}
