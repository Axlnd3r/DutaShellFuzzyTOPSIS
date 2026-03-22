<nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
    <div class="sb-sidenav-menu">
        <div class="nav">
            {{-- <a class="nav-link" href="{{ url('dashboard') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Dashboard
            </a> --}}

            <div class="sb-sidenav-menu-heading">Menu</div>
            <a class="nav-link" href="{{ url('project') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Project
            </a>
            
            <a class="nav-link collapsed" href="#" data-bs-toggle="collapse" data-bs-target="#collapseAttribute" aria-expanded="false" aria-controls="collapseAttribute">
                <div class="sb-nav-link-icon"><i class="fas fa-columns"></i></div>
                Attribute Menu
                <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
            </a>
            <div class="collapse" id="collapseAttribute" aria-labelledby="headingOne" data-bs-parent="#sidenavAccordion">
                <nav class="sb-sidenav-menu-nested nav">
                    <a class="nav-link" href="{{ url('attributte') }}">Attribute</a>
                    <a class="nav-link" href="{{ url('attributteValue') }}">Attribute Value</a>
                </nav>
            </div>

            <a class="nav-link {{ request()->is('generateCase*') ? 'active' : '' }}" href="{{ url('generateCase') }}">
                    <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                    Generate Case
                </a>

            <a class="nav-link {{ request()->is('tree*') ? 'active' : '' }}" href="{{ url('tree') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Decision Tree
            </a>

            <a class="nav-link {{ request()->is('SupportVectorMachine*') ? 'active' : '' }}" href="{{ url('SupportVectorMachine') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Support Vector Machine
            </a>

            <a class="nav-link {{ (request()->is('randomforest*') || request()->routeIs('randomforest.*')) ? 'active text-white' : '' }}" href="{{ route('randomforest.index') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Random Forest
            </a>

            <a class="nav-link {{ request()->is('HybridSim*') ? 'active' : '' }}" href="{{ route('HybridSim.show') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Hybrid Similarity
            </a>

            <a class="nav-link {{ request()->is('evaluation*') ? 'active' : '' }}" href="{{ route('evaluation.show') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-chart-bar"></i></div>
                Evaluasi Perbandingan
            </a>

            <a class="nav-link {{ request()->is('rule*') ? 'active' : '' }}" href="{{ url('rule') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Rule
            </a>

            <a class="nav-link {{ request()->is('consultation*') ? 'active' : '' }}" href="{{ url('consultation') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                Consultation
            </a>

            <a class="nav-link {{ request()->is('history*') ? 'active' : '' }}" href="{{ url('history') }}">
                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                History
            </a>

        </div>
    </div>
</nav>
