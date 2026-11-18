<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\PricingCatalogRepository;
use App\Pixelforge\PricingEngine;
use App\Support\CreditPacks;
use App\Support\GenerationLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(
        private readonly PricingCatalogRepository $catalogue,
        private readonly PricingEngine $pricing,
    ) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Generations/Create', [
            'sizes' => GenerationSize::options(),
            'qualities' => GenerationQuality::options(),
            'limits' => GenerationLimits::forDisplay(),
            'creditPacks' => CreditPacks::all(),
            'markupPercent' => (int) config('services.pixelforge.markup_percent', 25),
            'initialEstimate' => $this->quote('', GenerationSize::Square, GenerationQuality::Medium, 0),
            'balance' => $this->balanceFor($request),
        ]);
    }

    public function estimate(EstimateGenerationRequest $request): JsonResponse
    {
        return response()->json([
            'estimate' => $this->quote(
                $request->prompt(),
                $request->size(),
                $request->quality(),
                $request->referenceImageCount(),
            ),
        ]);
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()
            ->generations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: 4, pageName: 'page')
            ->withQueryString();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'balance' => $this->balanceFor($request),

            'hasPending' => $request->user()->generations()->awaitingProvider()->exists(),
            'creditPacks' => CreditPacks::all(),
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

    private function balanceFor(Request $request): array
    {
        $user = $request->user();
        $available = $user->available();

        return [
            'balance' => $user->balance()->toDisplayString(4),
            'held' => $user->held()->toDisplayString(4),
            'available' => $available->toDisplayString(4),
            'available_nanodollars' => $available->nanodollars,
        ];
    }
}
