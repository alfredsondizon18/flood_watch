document.addEventListener('DOMContentLoaded', function () {
    const searchInputs = Array.from(
        document.querySelectorAll('input[data-live-search]')
    );

    searchInputs.forEach(function (searchInput) {
        const targets = (searchInput.dataset.liveSearchTargets || '')
            .split('|')
            .map(selector => selector.trim())
            .filter(Boolean);

        if (targets.length === 0) {
            console.error('Live search is missing its result targets.');
            return;
        }

        let searchTimer;
        let requestController = null;

        function loadResults(page = 1) {
            if (requestController) {
                requestController.abort();
            }

            requestController = new AbortController();
            const url = new URL(window.location.href);
            const search = searchInput.value.trim();

            if (search) {
                url.searchParams.set('search', search);
            } else {
                url.searchParams.delete('search');
            }

            if (page > 1) {
                url.searchParams.set('page', page);
            } else {
                url.searchParams.delete('page');
            }

            const targetElements = targets.map(selector =>
                document.querySelector(selector)
            );

            if (targetElements.some(target => !target)) {
                console.error('Live search could not find a configured result target.');
                return;
            }

            targetElements.forEach(target => {
                target.style.opacity = '0.45';
                target.setAttribute('aria-busy', 'true');
            });

            fetch(url.toString(), {
                signal: requestController.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Unable to load search results.');
                    }
                    return response.text();
                })
                .then(html => {
                    const parsedPage = new DOMParser().parseFromString(html, 'text/html');
                    const replacements = targets.map(selector =>
                        parsedPage.querySelector(selector)
                    );

                    if (replacements.some(replacement => !replacement)) {
                        throw new Error('The search response is missing a result section.');
                    }

                    targetElements.forEach((target, index) => {
                        target.innerHTML = replacements[index].innerHTML;
                        target.style.opacity = '1';
                        target.removeAttribute('aria-busy');
                    });

                    const currentUrl = new URL(window.location.href);
                    if (search) {
                        currentUrl.searchParams.set('search', search);
                    } else {
                        currentUrl.searchParams.delete('search');
                    }

                    if (page > 1) {
                        currentUrl.searchParams.set('page', page);
                    } else {
                        currentUrl.searchParams.delete('page');
                    }

                    window.history.replaceState({}, '', currentUrl);
                })
                .catch(error => {
                    if (error.name !== 'AbortError') {
                        console.error(error);
                    }
                })
                .finally(() => {
                    targetElements.forEach(target => {
                        target.style.opacity = '1';
                        target.removeAttribute('aria-busy');
                    });
                });
        }

        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadResults(1), 300);
        });

        const form = searchInput.closest('form');
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                window.clearTimeout(searchTimer);
                loadResults(1);
            });

            const clearButton = form.querySelector('.clear-button');
            if (clearButton) {
                clearButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    searchInput.value = '';
                    window.clearTimeout(searchTimer);
                    loadResults(1);
                });
            }
        }

        document.addEventListener('click', function (event) {
            const clickedElement = event.target instanceof Element
                ? event.target
                : event.target.parentElement;
            const link = clickedElement && clickedElement.closest('a[href]');
            if (!link || !targets.some(selector => {
                const target = document.querySelector(selector);
                return target && target.contains(link);
            })) {
                return;
            }

            const linkUrl = new URL(link.href, window.location.href);
            if (linkUrl.pathname !== window.location.pathname ||
                !linkUrl.searchParams.has('page')) {
                return;
            }

            event.preventDefault();
            window.clearTimeout(searchTimer);
            loadResults(Math.max(1, Number(linkUrl.searchParams.get('page')) || 1));
        });
    });
});
