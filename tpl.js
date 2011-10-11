// Add another row for page selection based on category
function add_category_row(link)
{
    var i = 0;
    while (document.getElementById('category-row-'+i))
        i++;
    var last = document.getElementById('category-row-'+(i-1));
    var tr = document.createElement('tr');
    tr.id = 'category-row-'+i;
    var td = document.createElement('td');
    td.className = 'tpl_and';
    td.innerHTML = 'AND';
    tr.appendChild(td);
    td = document.createElement('td');
    td.innerHTML = last.cells.item(1).innerHTML;
    tr.appendChild(td);
    td = document.createElement('td');
    td.innerHTML = last.cells.item(2).innerHTML
        .replace(/<\/span>.*/, '</span>')
        .replace(/\[\d+\]/g, '['+i+']')
        .replace(/subcategory\d+/g, 'subcategory'+i);
    tr.appendChild(td);
    last.parentNode.insertBefore(tr, last.nextSibling);
}
