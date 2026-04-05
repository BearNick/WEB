const copyEmailBtn = document.getElementById('copyEmailBtn');

if (copyEmailBtn) {
  copyEmailBtn.addEventListener('click', async () => {
    const email = copyEmailBtn.dataset.email || '';
    try {
      await navigator.clipboard.writeText(email);
      const originalText = copyEmailBtn.textContent;
      copyEmailBtn.textContent = 'Email скопирован';
      setTimeout(() => {
        copyEmailBtn.textContent = originalText;
      }, 1800);
    } catch (error) {
      window.location.href = `mailto:${email}`;
    }
  });
}
