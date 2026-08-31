<?php

declare(strict_types=1);

namespace App\Domain\Coverage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One way from the street network.
 *
 * @property int $id
 * @property string $source
 * @property string $source_id
 * @property string|null $name
 * @property string|null $ref
 * @property string $highway
 */
final class Road extends Model
{
    /**
     * The classes worth drawing, coarsest first.
     *
     * Ordered because it is also the drawing order: a residential street drawn
     * over a trunk route makes the trunk route disappear at the junction, and
     * the junction is the thing somebody is navigating by.
     */
    public const CLASSES = [
        'motorway', 'trunk', 'primary', 'secondary', 'tertiary',
        'unclassified', 'residential',
    ];

    /** The ones that carry a whole mandate's shape at low zoom. */
    public const MAJOR = ['motorway', 'trunk', 'primary', 'secondary'];

    protected $fillable = ['source', 'source_id', 'name', 'ref', 'highway'];
}
