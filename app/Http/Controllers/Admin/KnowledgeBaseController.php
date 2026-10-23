<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class KnowledgeBaseController extends Controller
{
    private const SERVICE_OVERVIEWS = ['services_overview', 'general', 'complete-service-list-swahili'];

    public function services(): View
    {
        return view('admin.knowledge-base.services.index', [
            'entries' => KnowledgeBase::query()
                ->where('category', 'services')
                ->whereNotIn('subcategory', self::SERVICE_OVERVIEWS)
                ->orderBy('subcategory')
                ->paginate(20),
        ]);
    }

    public function serviceCreate(): View
    {
        return $this->formView(new KnowledgeBase, true, route('admin.knowledge-services.store'), 'POST');
    }

    public function serviceStore(Request $request): RedirectResponse
    {
        KnowledgeBase::query()->create($this->validatedEntry($request, true));

        return redirect()->route('admin.knowledge-services.index')->with('status', 'Service created.');
    }

    public function serviceEdit(KnowledgeBase $knowledgeBase): View
    {
        $this->ensureServiceEntry($knowledgeBase);

        return $this->formView($knowledgeBase, true, route('admin.knowledge-services.update', $knowledgeBase), 'PUT');
    }

    public function serviceUpdate(Request $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $this->ensureServiceEntry($knowledgeBase);
        $knowledgeBase->update($this->validatedEntry($request, true));

        return redirect()->route('admin.knowledge-services.index')->with('status', 'Service updated.');
    }

    public function serviceDestroy(KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $this->ensureServiceEntry($knowledgeBase);
        $knowledgeBase->delete();

        return back()->with('status', 'Service deleted.');
    }

    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();

        $entries = KnowledgeBase::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('question', 'like', '%'.$search.'%')
                        ->orWhere('answer', 'like', '%'.$search.'%')
                        ->orWhere('category', 'like', '%'.$search.'%')
                        ->orWhere('subcategory', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.knowledge-base.index', compact('entries', 'search'));
    }

    public function create(): View
    {
        return $this->formView(new KnowledgeBase, false, route('admin.knowledge-base.store'), 'POST');
    }

    public function store(Request $request): RedirectResponse
    {
        KnowledgeBase::query()->create($this->validatedEntry($request));

        return redirect()->route('admin.knowledge-base.index')->with('status', 'Knowledge entry created.');
    }

    public function edit(KnowledgeBase $knowledgeBase): View
    {
        return $this->formView(
            $knowledgeBase,
            false,
            route('admin.knowledge-base.update', $knowledgeBase),
            'PUT',
        );
    }

    public function update(Request $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $knowledgeBase->update($this->validatedEntry($request));

        return redirect()->route('admin.knowledge-base.index')->with('status', 'Knowledge entry updated.');
    }

    public function destroy(KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $knowledgeBase->delete();

        return back()->with('status', 'Knowledge entry deleted.');
    }

    private function formView(KnowledgeBase $entry, bool $serviceOnly, string $actionUrl, string $method): View
    {
        return view('admin.knowledge-base.form', compact('entry', 'serviceOnly', 'actionUrl', 'method'));
    }

    /**
     * @return array{category: string, language: string, subcategory: string, keywords: array<int, string>, question: string, answer: string, source: ?string, last_updated: string}
     */
    private function validatedEntry(Request $request, bool $serviceOnly = false): array
    {
        $data = $request->validate([
            'category' => [
                $serviceOnly ? 'nullable' : 'required',
                Rule::in([
                    'service',
                    'services',
                    'general_info',
                    'policy',
                    'insurance',
                    'location',
                    'hours',
                    'emergency',
                    'payment',
                    'appointments',
                    'doctors',
                    'visiting',
                    'amenities',
                    'resources',
                    'greeting',
                    'general',
                ]),
            ],
            'language' => ['sometimes', 'string', Rule::in(['en', 'sw', 'both'])],
            'subcategory' => ['required', 'string', 'max:100'],
            'keywords' => ['required', 'string', 'max:2000'],
            'question' => ['required', 'string', 'max:1000'],
            'answer' => ['required', 'string', 'max:10000'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $keywords = collect(preg_split('/[,;\r\n]+/u', $data['keywords']) ?: [])
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($keywords === []) {
            throw ValidationException::withMessages([
                'keywords' => 'Enter at least one keyword, separated by commas or new lines.',
            ]);
        }

        return [
            'category' => $serviceOnly ? 'services' : $data['category'],
            'language' => $data['language'] ?? 'both',
            'subcategory' => $data['subcategory'],
            'keywords' => $keywords,
            'question' => $data['question'],
            'answer' => $data['answer'],
            'source' => ($data['source'] ?? null) ?: hospital()?->name.' Administration',
            'last_updated' => now()->toDateString(),
        ];
    }

    private function ensureServiceEntry(KnowledgeBase $entry): void
    {
        abort_unless(
            $entry->category === 'services' && ! in_array($entry->subcategory, self::SERVICE_OVERVIEWS, true),
            404,
        );
    }
}
