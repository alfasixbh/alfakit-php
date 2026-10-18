<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Pivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $table = 'pivot_courses';

    protected $guarded = [];

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'pivot_course_student', 'course_id', 'student_id')
            ->withPivot(['id', 'role', 'seat'])
            ->withTimestamps();
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'course_id');
    }
}
