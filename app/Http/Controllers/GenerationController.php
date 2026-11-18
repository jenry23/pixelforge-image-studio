<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PricingCatalogRepository;
use App\Pixelforge\PricingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(
        private readonly PricingCatalogRepository $catalogue,
        private readonly PricingEngine $pricing,
    ) {}

    /** The generate page, with an opening quote so the cost is never blank. */
    public function create(Request $request): Response
    {
        return Inertia::render('Generations/Create', [
            'sizes' => GenerationSize::options(),
            'qualities' => GenerationQuality::options(),
            'limits' => [
                'prompt_max_characters' => 4000,
                'reference_images_max' => 4,
            ],
            'markupPercent' => (int) config('services.pixelforge.markup_percent', 25),
            'initialEstimate' => $this->quote('', GenerationSize::Square, GenerationQuality::Medium, 0),
            'balance' => $this->balanceFor($request),
        ]);
    }

    /**
     * Price a prospective request.
     *
     * The frontend debounces onto this rather than reimplementing the pricing
     * maths in JavaScript. One tested implementation, in PHP, is worth a small
     * round trip for a number users are going to rely on.
     */
    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_image_count' => ['nullable', 'integer', 'min:0', 'max:4'],
        ]);

        return response()->json([
            'estimate' => $this->quote(
                (string) ($validated['prompt'] ?? ''),
                GenerationSize::from($validated['size']),
                GenerationQuality::from($validated['quality']),
                (int) ($validated['reference_image_count'] ?? 0),
            ),
        ]);
    }

    /** The user's generations, newest first, with live status. */
    public function index(Request $request): Response
    {
        $generations = $request->user()
            ->generations()
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'balance' => $this->balanceFor($request),
            // Lets the frontend poll only while something is actually in flight,
            // so a settled history page makes no requests at all.
            'hasPending' => $request->user()->generations()->awaitingProvider()->exists(),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $createGeneration(
                user: $request->user(),
                prompt: $request->validated('prompt'),
                size: GenerationSize::from($request->validated('size')),
                quality: GenerationQuality::from($request->validated('quality')),
                referenceImages: $request->referenceImages(),
            );
        } catch (InsufficientCreditsException $e) {
            throw $e->toValidationException();
        }

        return to_route('generations.index')
            ->with('success', 'Your generation is on its way.');
    }

    /**
     * @return array<string, mixed>
     */
    private function quote(string $prompt, GenerationSize $size, GenerationQuality $quality, int $references): array
    {
        return $this->pricing->estimate(
            catalog: $this->catalogue->get(),
            prompt: $prompt,
            quality: $quality,
            size: $size,
            referenceImageCount: $references,
        )->jsonSerialize();
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceFor(Request $request): array
    {
        $user = $request->user();

        return [
            'balance' => $user->balance()->toDisplayString(4),
            'held' => $user->held()->toDisplayString(4),
            'available' => $user->available()->toDisplayString(4),
            'available_nanodollars' => $user->available()->nanodollars,
        ];
    }
}
