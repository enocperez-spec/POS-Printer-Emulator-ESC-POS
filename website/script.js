const header = document.querySelector('[data-header]');
const menuButton = document.querySelector('[data-menu-toggle]');
const nav = document.querySelector('[data-nav]');

const breadcrumbContainer = document.querySelector('.breadcrumbs');
const canonicalUrl = document.querySelector('link[rel="canonical"]')?.href;
if (breadcrumbContainer && canonicalUrl) {
  const items = Array.from(breadcrumbContainer.querySelectorAll('a, span'))
    .filter((element) => element.textContent.trim() !== '/')
    .map((element, index, elements) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: element.textContent.trim(),
      item: index === elements.length - 1
        ? canonicalUrl
        : new URL(element.getAttribute('href') || '/', window.location.origin).href
    }));

  const structuredData = document.createElement('script');
  structuredData.type = 'application/ld+json';
  structuredData.dataset.generated = 'breadcrumbs';
  structuredData.textContent = JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items
  });
  document.head.appendChild(structuredData);
}

function setHeaderState() {
  header?.classList.toggle('scrolled', window.scrollY > 24);
}

setHeaderState();
window.addEventListener('scroll', setHeaderState, { passive: true });

menuButton?.addEventListener('click', () => {
  const isOpen = nav.classList.toggle('open');
  menuButton.setAttribute('aria-expanded', String(isOpen));
});

nav?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
  nav.classList.remove('open');
  menuButton?.setAttribute('aria-expanded', 'false');
}));

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (reduceMotion || !('IntersectionObserver' in window)) {
  document.querySelectorAll('.reveal').forEach((element) => element.classList.add('visible'));
} else {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });
  document.querySelectorAll('.reveal').forEach((element) => observer.observe(element));
}
