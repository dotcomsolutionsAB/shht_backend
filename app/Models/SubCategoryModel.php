<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCategoryModel extends Model
{
    //
    protected $table = 't_sub_category';
    protected $fillable = ['category', 'name'];

    public function categoryRef()
    {
        // foreign key 'category' → t_category.id
        return $this->belongsTo(CategoryModel::class, 'category', 'id');
    }
}
