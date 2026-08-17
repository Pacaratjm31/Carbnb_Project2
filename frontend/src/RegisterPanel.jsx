import { useState } from 'react';

function RegisterPanel() {
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState('renter');
  const [message, setMessage] = useState('');

  const handleRegister = async (event) => {
    event.preventDefault();
    const formData = new FormData();
    formData.append('full_name', fullName);
    formData.append('email', email);
    formData.append('password', password);
    formData.append('role', role);

    const response = await fetch('../api/register.php', {
      method: 'POST',
      body: formData
    });

    const data = await response.json();
    setMessage(data.message || 'Registration result unavailable');
  };

  return (
    <div className="card">
      <h3>Create Account</h3>
      <form onSubmit={handleRegister} style={{ display: 'grid', gap: '10px' }}>
        <input value={fullName} onChange={(e) => setFullName(e.target.value)} placeholder="Full Name" />
        <input value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Email" />
        <input value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Password" type="password" />
        <select value={role} onChange={(e) => setRole(e.target.value)}>
          <option value="renter">Renter</option>
          <option value="owner">Owner</option>
          <option value="admin">Admin</option>
        </select>
        <button className="btn" type="submit">Register</button>
      </form>
      {message && <p className="small" style={{ marginTop: '10px' }}>{message}</p>}
    </div>
  );
}

export default RegisterPanel;
