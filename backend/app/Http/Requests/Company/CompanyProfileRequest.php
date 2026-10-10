<?php

namespace App\Http\Requests\Company;

use App\Services\Company\CompanyAccess;
use Illuminate\Foundation\Http\FormRequest;

class CompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CompanyAccess::class)->require($this->user());

        return true;
    }

    public function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:1'], 'profile' => ['required', 'array:name,tagline,about,phone,email,address,website,whatsapp,hours,logo,slides,activities,offers,projects,social,visibility'], 'profile.name' => ['required', 'string', 'max:200'], 'profile.tagline' => ['present', 'nullable', 'string', 'max:500'], 'profile.about' => ['present', 'nullable', 'string', 'max:10000'], 'profile.phone' => ['present', 'nullable', 'string', 'max:100'], 'profile.email' => ['present', 'nullable', 'email:rfc', 'max:200'], 'profile.address' => ['present', 'nullable', 'string', 'max:500'], 'profile.website' => ['present', 'nullable', 'url:https', 'max:2000'], 'profile.whatsapp' => ['present', 'nullable', 'regex:/^[+0-9 ()-]{1,100}$/'], 'profile.hours' => ['present', 'nullable', 'string', 'max:300'], 'profile.logo' => ['present', 'nullable', 'uuid', 'exists:company_assets,id'], 'profile.visibility' => ['required', 'array:slides,about,activities,offers,projects,social,care'], 'profile.social' => ['present', 'array', 'max:30'], 'profile.social.*' => ['array:name,url'], 'profile.social.*.name' => ['required', 'string', 'max:120'], 'profile.social.*.url' => ['required', 'url:https', 'max:2000']];
        foreach (['slides', 'about', 'activities', 'offers', 'projects', 'social', 'care'] as $key) {
            $rules['profile.visibility.'.$key] = ['required', 'boolean'];
        }
        foreach (['slides', 'activities', 'offers', 'projects'] as $key) {
            $base = 'profile.'.$key;
            $rules[$base] = ['present', 'array', 'max:30'];
            $rules[$base.'.*'] = ['array:title,caption,description,image,link,visible'];
            $rules[$base.'.*.title'] = ['required', 'string', 'max:200'];
            $rules[$base.'.*.caption'] = ['sometimes', 'nullable', 'string', 'max:200'];
            $rules[$base.'.*.description'] = ['present', 'nullable', 'string', 'max:10000'];
            $rules[$base.'.*.image'] = ['present', 'nullable', 'uuid', 'exists:company_assets,id'];
            $rules[$base.'.*.link'] = ['present', 'nullable', 'url:https', 'max:2000'];
            $rules[$base.'.*.visible'] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), ['version', 'profile'])) {
                $validator->errors()->add('payload', 'حقول غير مسموحة.');
            }
            $profile = $this->input('profile', []);
            if (! is_array($profile)) {
                return;
            }
            $links = [$profile['website'] ?? ''];
            foreach (['slides', 'activities', 'offers', 'projects'] as $key) {
                foreach ((array) ($profile[$key] ?? []) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $links[] = $item['link'] ?? '';
                }
            }
            foreach ((array) ($profile['social'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $links[] = $item['url'] ?? '';
            }
            foreach ($links as $link) {
                if (is_string($link) && $link !== '' && (isset(parse_url($link)['user']) || isset(parse_url($link)['pass']) || preg_match('/[\x00-\x1F]/', $link))) {
                    $validator->errors()->add('profile', 'الرابط يحتوي بيانات أو أحرف غير مسموحة.');
                }
            }
        }];
    }
}
