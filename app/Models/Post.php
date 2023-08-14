<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Post extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'content'];

    protected $casts = ['featured' => 'boolean'];

    /**
     * @return BelongsToMany
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            $article->slug = Str::slug($article->title);
        });
    }

    /**
     * @param int $length
     * @return string
     */
    public function limitedContent(int $length = 150): string
    {
        if (strlen($this->content) <= $length) {
            return $this->content;
        }

        $truncatedContent = substr($this->content, 0, $length);

        // Vérifier si le prochain caractère après la limite est un espace
        if ($this->content[$length] !== ' ') {
            // Trouver la position du dernier espace avant la limite
            $lastSpace = strrpos($truncatedContent, ' ');

            // Si un espace est trouvé, utiliser le texte jusqu'à cet espace
            if ($lastSpace !== false) {
                $truncatedContent = substr($truncatedContent, 0, $lastSpace);
            }
        }

        return $truncatedContent . (strlen($this->content) > $length ? '...' : '');
    }
}
