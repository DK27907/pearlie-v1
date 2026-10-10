@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-wrap items-center justify-between gap-4">
        <p class="text-sm text-gray-700 leading-5 dark:text-gray-300">
            {!! __('Showing') !!}
            @if ($paginator->firstItem())
                <span class="font-medium">{{ $paginator->firstItem() }}</span>
                {!! __('to') !!}
                <span class="font-medium">{{ $paginator->lastItem() }}</span>
            @else
                {{ $paginator->count() }}
            @endif
            {!! __('of') !!}
            <span class="font-medium">{{ $paginator->total() }}</span>
            {!! __('results') !!}
        </p>

        <div class="inline-flex shadow-sm">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="inline-flex items-center rounded-l-md border border-gray-300 px-3 py-2 text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
                    <span class="sr-only">{{ __('pagination.previous') }}</span>
                    <span aria-hidden="true">&laquo;</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}" class="inline-flex items-center rounded-l-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-900">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true" class="inline-flex items-center border border-gray-300 px-3 py-2 text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
                        <span class="sr-only">{{ __('More pages') }}</span>
                        <span aria-hidden="true">{{ $element }}</span>
                    </span>
                @elseif (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="inline-flex items-center border border-gray-300 bg-gray-200 px-3 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}" class="inline-flex items-center border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-900">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}" class="inline-flex items-center rounded-r-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-900">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            @else
                <span aria-disabled="true" class="inline-flex items-center rounded-r-md border border-gray-300 px-3 py-2 text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
                    <span class="sr-only">{{ __('pagination.next') }}</span>
                    <span aria-hidden="true">&raquo;</span>
                </span>
            @endif
        </div>
    </nav>
@endif
