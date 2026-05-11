function copyPassword(){
      const value = "123456789";
      if(navigator.clipboard && window.isSecureContext){
        navigator.clipboard.writeText(value).then(showToast).catch(() => fallbackCopy(value));
      } else {
        fallbackCopy(value);
      }
    }
    function fallbackCopy(value){
      const input=document.createElement("input");
      input.value=value;
      document.body.appendChild(input);
      input.select();
      document.execCommand("copy");
      document.body.removeChild(input);
      showToast();
    }
    function showToast(){
      const t=document.getElementById("toast");
      t.style.display="block";
      setTimeout(()=>t.style.display="none",1800);
    }
    function toggleQR(){
      const q=document.getElementById("qr");
      q.style.display = q.style.display === "block" ? "none" : "block";
    }
    function setLang(lang){
      document.documentElement.lang = lang;
      document.querySelectorAll("[data-en][data-sq]").forEach(el => {
        el.textContent = el.getAttribute("data-" + lang);
      });
      document.querySelectorAll(".lang button").forEach(b => b.classList.remove("active"));
      [...document.querySelectorAll(".lang button")].find(b => b.textContent.trim().toLowerCase() === lang)?.classList.add("active");
    }
    document.querySelectorAll(".cat").forEach(button => {
      button.addEventListener("click", () => {
        document.querySelectorAll(".cat").forEach(b => b.classList.remove("active"));
        button.classList.add("active");
        const filter = button.dataset.filter;
        document.querySelectorAll(".menu-section").forEach(section => {
          section.style.display = filter === "all" || section.dataset.section === filter ? "block" : "none";
        });
      });
    });
