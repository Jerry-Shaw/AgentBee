<script setup lang="ts">
/**
 * 等待中的动态图标：一只上下起伏、扇翅膀的小蜜蜂。
 *
 * 纯 SVG 描边（和 lucide 那批图标同一套画法），用 `currentColor`，所以跟着主题和
 * 字号走。所有动画都只动 transform，不会引起重排；`prefers-reduced-motion` 下停掉。
 */
withDefaults(defineProps<{
  size?: number;
}>(), {
  size: 22,
});
</script>

<template>
  <svg
    class="bee-loader"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <g class="bee-loader-body">
      <!-- 翅膀画在身体下面，扇动时以根部为轴压扁 -->
      <ellipse class="bee-wing bee-wing-left" cx="5.1" cy="10.6" rx="4.1" ry="2.3" />
      <ellipse class="bee-wing bee-wing-right" cx="18.9" cy="10.6" rx="4.1" ry="2.3" />

      <!-- 触角 -->
      <path d="M10.4 5.3C9.6 3.8 8.6 3.2 7.4 3.2" />
      <path d="M13.6 5.3C14.4 3.8 15.4 3.2 16.6 3.2" />

      <!-- 头 + 身体：头只压住身体一点点，别糊成一坨 -->
      <circle cx="12" cy="7.4" r="2.7" />
      <ellipse cx="12" cy="15" rx="5.2" ry="6.2" />

      <!-- 身上的两道条纹 + 尾针 -->
      <path d="M7.7 12.9H16.3" />
      <path d="M8.5 16.1H15.5" />
      <path d="M12 21.2V22.6" />
    </g>
  </svg>
</template>

<style scoped>
.bee-loader {
  display: block;
  flex: 0 0 auto;
  overflow: visible;
}

/* 整只蜜蜂上下起伏 */
.bee-loader-body {
  animation: bee-bob 1.1s ease-in-out infinite;
}

/* 翅膀以根部为轴扇动 */
.bee-wing {
  transform-box: fill-box;
  transform-origin: 50% 100%;
  animation: bee-flap 0.28s ease-in-out infinite;
}

.bee-wing-left {
  animation-delay: 0.02s;
}

.bee-wing-right {
  animation-delay: 0.06s;
}

@keyframes bee-bob {
  0%,
  100% {
    transform: translateY(0);
  }

  50% {
    transform: translateY(-1.6px);
  }
}

@keyframes bee-flap {
  0%,
  100% {
    transform: scaleY(1);
  }

  50% {
    transform: scaleY(0.32);
  }
}

@media (prefers-reduced-motion: reduce) {
  .bee-loader-body,
  .bee-wing {
    animation: none;
  }
}
</style>
