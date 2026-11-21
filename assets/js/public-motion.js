(() => {
    const reduceMotion = window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const canAnimate = typeof Element !== "undefined" && Element.prototype.animate;
    const enterDuration = reduceMotion ? 140 : 640;
    const easing = "cubic-bezier(.22,1,.36,1)";
    function animateOne(element, keyframes, options = {}) {
        if (!element || !canAnimate) {
            return;
        }
        element.animate(keyframes, {
            duration: enterDuration,
            easing,
            fill: "both",
            ...options,
        });
    }
    function animateGroup(items, keyframes, options = {}) {
        Array.from(items).forEach((item, index) => {
            animateOne(item, keyframes, {
                delay: reduceMotion ? 0 : index * (options.stagger || 55),
                ...options,
            });
        });
    }
    function revealHero() {
        const heroItems = document.querySelectorAll(".hero .eyebrow, .hero h1, .hero-lead, .hero-actions, .hero-stats");
        animateGroup(heroItems, [
            { opacity: 0, transform: `translate3d(0, ${reduceMotion ? 0 : 24}px, 0)` },
            { opacity: 1, transform: "translate3d(0, 0, 0)" },
        ], { stagger: 70 });
        if (reduceMotion) {
            return;
        }
        animateGroup(document.querySelectorAll(".hero-floating-card"), [
            { opacity: 0, transform: "translate3d(0, 22px, 0) scale(.97)" },
            { opacity: 1, transform: "translate3d(0, 0, 0) scale(1)" },
        ], { delay: 260, stagger: 90, duration: 760 });
    }
    function animateQuizSteps() {
        const quiz = document.getElementById("quizForm");
        if (!quiz) {
            return;
        }
        function revealActiveStep() {
            const step = quiz.querySelector(".quiz-step.active");
            animateOne(step, [
                { opacity: 0, transform: `translate3d(${reduceMotion ? 0 : 14}px, 0, 0)` },
                { opacity: 1, transform: "translate3d(0, 0, 0)" },
            ], { duration: reduceMotion ? 120 : 280 });
        }
        revealActiveStep();
        quiz.addEventListener("click", (event) => {
            if (event.target.closest("#quizNext, #quizPrev")) {
                window.requestAnimationFrame(revealActiveStep);
            }
        });
    }
    function initPublicMotion() {
        revealHero();
        animateQuizSteps();
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPublicMotion, { once: true });
    } else {
        initPublicMotion();
    }
})();
