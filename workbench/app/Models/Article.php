<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Pijler\LaravelTags\Traits\HasTags;

class Article extends Model
{
    use HasFactory;
    use HasTags;

    /**
     * The attributes that aren't mass assignable.
     */
    protected $guarded = [];
}
