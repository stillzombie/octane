<?php

namespace App\Http\Livewire\Translation;

use App\Models\Application;
use App\Models\Locale;
use App\Models\Tenant;
use Livewire\Component;
use App\Models\Translation;
use App\Models\TranslationKey;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;

class Store extends Component
{
    public Locale $locale;
    public $translations = [];
    public $tenants = [];
    public $translationKeys;
    public $domains;
    public $filter = '';
    public $filters = [
        'search' => '',
    ];

    protected $rules = [
        'translations.*' => 'sometimes',
        'tenants.*' => 'sometimes',
        'locale.locale' => 'required',
    ];

    public function mount(Locale $locale)
    {
        $this->translationKeys = TranslationKey::get();
        $this->domains = $this->translationKeys->pluck('domain')->unique()->toArray();
        if(! $locale->id ) {
            $this->locale = Locale::make([
                'locale' => ''
            ]);
            return;
        }

        $this->tenants = Tenant::get()->mapWithKeys(function($tenant){
            return [$tenant->id => $this->locale->tenants()->pluck('id')->intersect([$tenant->id])->count() ];
        });

        $this->translations = $this->locale->translations()->get()->mapWithKeys(function($translation){
            return [$translation->key_id => $translation->value];
        })->toArray();

    }

    public function save()
    {
        $this->validate();
        $prefilled = collect();
        if(! $this->locale->exists) {
            $prefilled = $this->prefilldashboard(collect([
                'validation.required'=> 'The :attribute field is required.',
                'validation.numeric'=> 'The :attribute must be a number.',
                'validation.min.string'=> 'The :attribute must be at least :min characters.',
                'validation.max.string'=> 'The :attribute may not be greater than :max characters.',
                'validation.email'=> 'The :attribute must be a valid email address.',
                'validation.unique'=> 'The :attribute has already been taken.',
                'validation.gt.numeric'=> 'The :attribute must be greater than :value.'
            ]));
        }

        $this->locale->save();
        $this->locale->fresh();
        $localeId = $this->locale->id;
        $prefilled->each(function($translation) use($localeId) {
            $translation->locale_id = $localeId;
            $translation->save();
        });

        list($attached, $detached) = Tenant::get()->partition(function ($tenant) {
            return collect($this->tenants)->filter()->has($tenant->id);
        });

        Application::whereIn('tenant_id', $detached->pluck('id')->toArray() )->update(['locale_id' => null]);

        $this->locale->tenants()->sync(
            collect($this->tenants)->filter()->keys()->toArray()
        );

        collect($this->translations)->each(function ($item, $key) {
            $translationKey = TranslationKey::where('id', '=', $key)->first();

            Translation::updateOrCreate(
                [
                    'locale_id' => $this->locale->id,
                    'key_id' => $translationKey->id,
                    'domain' => $translationKey->domain,
                    'key' =>  $translationKey->key,
                ],
                [
                    'value' => $item
                ]
            );
        });

        return redirect()->to('translations');
    }

    protected function prefilldashboard($translations)
    {
        return $translations->map(function($translation, $key) {
            $translationKey = TranslationKey::where('key', '=', $key)->first();

            return Translation::make(
                [
                    'key_id' => $translationKey->id,
                    'domain' => $translationKey->domain,
                    'key' =>  $translationKey->key,
                    'value' => $translation
                ]
            );
        });
    }

    public function render()
    {
        return view('livewire.translation.store', [
            'keys' => TranslationKey::
            when($this->filters['search'], function($query, $search){
                $query->where('key', 'like', '%'.$search.'%');
            })
            ->when($this->filter, fn ($query, $filter) => $query->where('domain', $filter))->get()
        ])->layout('components.layout');
    }
}
