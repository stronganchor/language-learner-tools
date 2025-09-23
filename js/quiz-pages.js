document.addEventListener('DOMContentLoaded', function () {
    var wrappers = document.querySelectorAll('.ll-tools-quiz-iframe-wrapper');
    wrappers.forEach(function (wrapper) {
        var iframe = wrapper.querySelector('.ll-tools-quiz-iframe');
        var loader = wrapper.querySelector('.ll-tools-iframe-loading');
        if (!iframe || !loader) return;

        function hide() { loader.style.display = 'none'; }
        iframe.addEventListener('load', hide);
        iframe.addEventListener('error', hide);
        setTimeout(hide, 3000);

        try {
            var vh = (window.llQuizPages && parseInt(llQuizPages.vh, 10)) || 95;
            iframe.style.height = vh + 'vh';
            iframe.style.minHeight = vh + 'vh';
            wrapper.style.minHeight = vh + 'vh';
        } catch (e) { }
    });
});
