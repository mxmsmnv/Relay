(function () {
  'use strict';

  function openSection(section) {
    if (!section) return;
    if (section.classList.contains('InputfieldStateCollapsed')) {
      var header = section.querySelector(':scope > .InputfieldHeader');
      if (header) header.click();
    }
    window.requestAnimationFrame(function () {
      section.scrollIntoView({behavior: 'smooth', block: 'start'});
      var header = section.querySelector(':scope > .InputfieldHeader');
      if (header) header.setAttribute('tabindex', '-1');
      if (header) header.focus({preventScroll: true});
    });
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest('#ModuleEditForm .RelayConfigNav a[href^="#Inputfield_relay_config_"]');
    if (!link) return;
    var section = document.querySelector(link.getAttribute('href'));
    if (!section) return;
    event.preventDefault();
    history.replaceState(null, '', link.getAttribute('href'));
    openSection(section);
  });

  if (window.location.hash.indexOf('#Inputfield_relay_config_') === 0) {
    window.requestAnimationFrame(function () {
      openSection(document.querySelector(window.location.hash));
    });
  }
}());
