<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalle Anka-Bingo Admin</title>
    <link href="css/style.css" rel="stylesheet" type="text/css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .view-admin {
            width: 100%;
            max-width: 1200px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        .delete-btn {
            background-color: #d20202;
            padding: 5px 10px;
            font-size: 0.8em;
        }
    </style>
</head>
<body>
    <div id="container">
        <div id="header">
            Bingo Admin
        </div>
        
        <div id="app"></div>

    </div>

    <script>
        const API_URL = 'api.php';
        const app = $('#app');

        const Views = {
            Login: () => `
                <div class="view-login">
                    <h2>Admin Login</h2>
                    <input type="password" id="passwordInput" placeholder="Password" />
                    <button onclick="Handlers.login()">Login</button>
                    <p id="errorMsg" class="error"></p>
                </div>
            `,
            Dashboard: ({users, items}) => `
                <div class="view-dashboard view-admin">
                    <h2>Admin Dashboard</h2>
                    
                    <div class="section">
                        <h3>Hantera Bingorutor</h3>
                        <div class="add-form" style="margin: 20px 0; display: flex; gap: 10px;">
                            <input type="text" id="newItemContent" placeholder="Ny bingoruta text..." />
                            <button onclick="Handlers.addItem()">Lägg till</button>
                        </div>
                        <div class="items-table-container" style="max-height: 400px; overflow-y: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Text</th>
                                        <th>Åtgärd</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${items.map(i => `
                                        <tr>
                                            <td>${i.id}</td>
                                            <td>
                                                <input type="text" value="${i.content.replace(/"/g, '&quot;')}" id="item-${i.id}" style="width: 100%; padding: 10px; font-size: 1em;" />
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 5px;">
                                                    <button style="padding: 8px 15px;" onclick="Handlers.updateItem(${i.id})">Spara</button>
                                                    <button class="delete-btn" style="padding: 8px 15px;" onclick="Handlers.deleteItem(${i.id})">Ta bort</button>
                                                </div>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="section" style="margin-top: 40px;">
                        <h3>Användare</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Användarnamn</th>
                                    <th>Skapad</th>
                                    <th>Åtgärd</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${users.map(u => `
                                    <tr>
                                        <td>${u.id}</td>
                                        <td>${u.username}</td>
                                        <td>${u.created_at}</td>
                                        <td>
                                            <button class="delete-btn" onclick="Handlers.deleteUser(${u.id})">Ta bort</button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `
        };

        const Handlers = {
            init: async () => {
                const res = await fetch(`${API_URL}?action=check_admin`);
                const data = await res.json();
                if (data.isAdmin) {
                    Handlers.loadDashboard();
                } else {
                    Handlers.render('Login');
                }
            },

            login: async () => {
                const password = $('#passwordInput').val();
                const res = await fetch(`${API_URL}?action=admin_login`, {
                    method: 'POST',
                    body: JSON.stringify({ password })
                });
                const data = await res.json();
                if (data.success) {
                    Handlers.loadDashboard();
                } else {
                    $('#errorMsg').text(data.error);
                }
            },

            loadDashboard: async () => {
                const [usersRes, itemsRes] = await Promise.all([
                    fetch(`${API_URL}?action=get_users`),
                    fetch(`${API_URL}?action=get_items`)
                ]);
                const users = await usersRes.json();
                const items = await itemsRes.json();
                Handlers.render('Dashboard', {users, items});
            },

            addItem: async () => {
                const content = $('#newItemContent').val();
                if(!content) return;
                
                const res = await fetch(`${API_URL}?action=admin_add_item`, {
                    method: 'POST',
                    body: JSON.stringify({ content })
                });
                const data = await res.json();
                if(data.success) Handlers.loadDashboard();
                else alert(data.error);
            },

            updateItem: async (id) => {
                const content = $(`#item-${id}`).val();
                const res = await fetch(`${API_URL}?action=admin_update_item`, {
                    method: 'POST',
                    body: JSON.stringify({ id, content })
                });
                const data = await res.json();
                if(data.success) {
                    alert('Sparat!');
                } else alert(data.error);
            },

            deleteItem: async (id) => {
                if(!confirm('Detta kommer ta bort rutan permanent! Är du säker?')) return;
                const res = await fetch(`${API_URL}?action=admin_delete_item`, {
                    method: 'POST',
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if(data.success) Handlers.loadDashboard();
                else alert(data.error);
            },

            deleteUser: async (id) => {
                if (!confirm('Är du säker på att du vill ta bort denna användare?')) return;
                
                const res = await fetch(`${API_URL}?action=delete_user`, {
                    method: 'POST',
                    body: JSON.stringify({ userId: id })
                });
                const data = await res.json();
                if (data.success) {
                    Handlers.loadDashboard();
                } else {
                    alert('Fel vid borttagning: ' + (data.error || 'Okänt fel'));
                }
            },

            render: (viewName, data) => {
                app.html(Views[viewName](data));
            }
        };

        $(document).ready(Handlers.init);
    </script>
</body>
</html>
