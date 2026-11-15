const search = document.querySelector('#search');
const cards = [...document.querySelectorAll('.card')];
const empty = document.querySelector('#no-results');
const normalize = text => text.toLocaleLowerCase('es-MX').normalize('NFD').replace(/\p{Diacritic}/gu, '');

search.addEventListener('input', () => {
    const query = normalize(search.value.trim());
    let visible = 0;
    for (const card of cards) {
        card.hidden = !normalize(card.textContent).includes(query);
        visible += Number(!card.hidden);
    }
    empty.hidden = visible > 0 || query === '';
});

for (const form of document.querySelectorAll('.delete')) {
    form.addEventListener('submit', event => {
        if (!window.confirm('¿Eliminar esta nota y su adjunto?')) event.preventDefault();
    });
}

for (const time of document.querySelectorAll('time')) {
    time.textContent = new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium', timeStyle: 'short'
    }).format(new Date(time.dateTime));
}
