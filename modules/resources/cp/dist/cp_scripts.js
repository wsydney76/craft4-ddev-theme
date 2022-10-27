setSidebarVisibility();

$('#sidebar  li.heading > span').click(
    function() {
        $(this).parent().toggleClass('collapsed')
        storeSidebarVisibility();
    }
);


function storeSidebarVisibility() {
    v = $('#sidebar > nav > ul > li').map(function() {
        return this.classList.contains('collapsed') ? 'hidden' : 'visible'
    });
    localStorage.sidebarVisiblity = v.toArray().join(',');
}

function setSidebarVisibility() {
    var v = localStorage.sidebarVisiblity;
    if (v === undefined) {
        return;
    }
    v = v.split(',');

    var nodes = $('#sidebar > nav > ul > li');

    // Check for changes: Different count
    if (nodes.length != v.length) {
        return;
    }

    for (var i = 0; i < nodes.length; i++) {
        node = nodes[i];
        if (v[i] == 'hidden') {
            node.classList.add('collapsed');
        }

    }
}
