import { tsParticles } from "@tsparticles/engine";
import { loadSlim } from "@tsparticles/slim";
import { animate, inView, scroll, stagger } from "motion";

const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)").matches;

document.documentElement.classList.add("motion-enhanced");

const initializeParticles = async () => {
  if (reducedMotion) {
    return;
  }

  await loadSlim(tsParticles);
  await tsParticles.load({
    id: "particles",
    options: {
      fullScreen: { enable: false },
      background: { color: { value: "transparent" } },
      detectRetina: true,
      fpsLimit: 60,
      pauseOnBlur: true,
      pauseOnOutsideViewport: true,
      particles: {
        color: {
          value: ["#65ddff", "#f2a441", "#ffffff"],
        },
        links: {
          color: "#65ddff",
          distance: 145,
          enable: true,
          opacity: 0.055,
          width: 0.7,
        },
        move: {
          direction: "none",
          enable: true,
          outModes: { default: "out" },
          random: true,
          speed: 0.32,
          straight: false,
        },
        number: {
          density: { enable: true },
          value: 52,
        },
        opacity: {
          value: { min: 0.12, max: 0.46 },
          animation: {
            enable: true,
            speed: 0.25,
            sync: false,
          },
        },
        shape: { type: "circle" },
        size: {
          value: { min: 0.7, max: 2.2 },
        },
      },
      interactivity: {
        detectsOn: "window",
        events: {
          onClick: { enable: false, mode: "push" },
          onHover: {
            enable: finePointer,
            mode: ["grab", "repulse"],
          },
          resize: { enable: true },
        },
        modes: {
          grab: {
            distance: 175,
            links: { opacity: 0.18 },
          },
          repulse: {
            distance: 72,
            duration: 0.45,
          },
        },
      },
      responsive: [
        {
          maxWidth: 800,
          options: {
            particles: {
              links: { distance: 105 },
              number: { value: 24 },
              move: { speed: 0.22 },
            },
          },
        },
      ],
    },
  });
};

void initializeParticles();

if (!reducedMotion) {
  animate(
    "[data-intro]",
    {
      opacity: [0, 1],
      y: [28, 0],
    },
    {
      delay: stagger(0.085),
      duration: 0.85,
      ease: [0.16, 1, 0.3, 1],
    },
  );

  inView(
    "[data-reveal]",
    (element) => {
      animate(
        element,
        {
          opacity: [0, 1],
          y: [42, 0],
          filter: ["blur(8px)", "blur(0px)"],
        },
        {
          duration: 0.85,
          ease: [0.16, 1, 0.3, 1],
        },
      );
    },
    { margin: "0px 0px -10% 0px", amount: 0.18 },
  );

  inView(
    "[data-stagger]",
    (container) => {
      const children = Array.from(container.children);

      animate(
        children,
        {
          opacity: [0, 1],
          y: [34, 0],
          scale: [0.975, 1],
        },
        {
          delay: stagger(0.085),
          duration: 0.75,
          ease: [0.16, 1, 0.3, 1],
        },
      );
    },
    { margin: "0px 0px -8% 0px", amount: 0.12 },
  );

  const hero = document.querySelector<HTMLElement>(".hero-section");
  const product = document.querySelector<HTMLElement>(".hero-product");

  if (hero && product) {
    scroll(
      (progress: number) => {
        product.style.setProperty("--scroll-lift", `${progress * 54}px`);
        product.style.setProperty("--scroll-scale", `${1 - progress * 0.025}`);
      },
      {
        target: hero,
        offset: ["start start", "end start"],
      },
    );
  }
}

scroll((progress: number) => {
  document.documentElement.style.setProperty("--page-progress", `${progress}`);
});

if (finePointer && !reducedMotion) {
  const cursorAura = document.querySelector<HTMLElement>(".cursor-aura");
  let pointerFrame = 0;
  let pointerX = window.innerWidth / 2;
  let pointerY = window.innerHeight / 2;

  const renderPointer = () => {
    document.documentElement.style.setProperty("--pointer-x", `${pointerX}px`);
    document.documentElement.style.setProperty("--pointer-y", `${pointerY}px`);
    cursorAura?.classList.add("is-visible");
    pointerFrame = 0;
  };

  window.addEventListener(
    "pointermove",
    (event) => {
      pointerX = event.clientX;
      pointerY = event.clientY;

      if (!pointerFrame) {
        pointerFrame = window.requestAnimationFrame(renderPointer);
      }
    },
    { passive: true },
  );

  document.querySelectorAll<HTMLElement>("[data-tilt]").forEach((element) => {
    element.addEventListener("pointermove", (event) => {
      const bounds = element.getBoundingClientRect();
      const x = (event.clientX - bounds.left) / bounds.width - 0.5;
      const y = (event.clientY - bounds.top) / bounds.height - 0.5;

      animate(
        element,
        {
          rotateX: y * -3.5,
          rotateY: x * 4.5,
          transformPerspective: 1100,
        },
        {
          duration: 0.32,
          ease: "easeOut",
        },
      );
    });

    element.addEventListener("pointerleave", () => {
      animate(
        element,
        {
          rotateX: 0,
          rotateY: 0,
        },
        {
          duration: 0.65,
          ease: [0.16, 1, 0.3, 1],
        },
      );
    });
  });

  document.querySelectorAll<HTMLElement>("[data-magnetic]").forEach((element) => {
    element.addEventListener("pointermove", (event) => {
      const bounds = element.getBoundingClientRect();
      const x = (event.clientX - bounds.left - bounds.width / 2) * 0.1;
      const y = (event.clientY - bounds.top - bounds.height / 2) * 0.12;

      animate(element, { x, y }, { duration: 0.24, ease: "easeOut" });
    });

    element.addEventListener("pointerleave", () => {
      animate(element, { x: 0, y: 0 }, { duration: 0.5, ease: [0.16, 1, 0.3, 1] });
    });
  });
}
