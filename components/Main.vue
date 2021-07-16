<template>
	<div>
		<!-- title -->
		<ap-title :data="title"></ap-title>

		<!-- text -->
		<ap-text class="mb-3" :data="text"></ap-text>

		<!-- input -->
		<ap-input ref="input" :data="input"></ap-input>

		<!-- button -->
		<ap-button :data="btnSend"></ap-button>

		<!-- toast -->
		<ap-toast ref="toast"></ap-toast>
	</div>
</template>

<script>
	module.exports = {
		data() {
			return {
				title: {
					text: 'Cupones'
				},
				text: {
					text: 'Inserte su cupón para canjearlo. Los cupones son palabras (por ejemplo: 1MERCUPON) que al canjearlas le darán créditos. Encontrará cupones en la app o los recibirá por correo.'
				},
				input: {
					icon:'fas fa-tags', 
					label:'Insertar cupón'
				},
				btnSend: {
					icon: 'fas fa-arrow-right',
					caption: 'Aplicar cupón',
					size: 'medium',
					isPrimary: true,
					onTap: this.send
				}
			}
		},
		methods: {
			send() {
				// get the coupon
				var coupon = this.$refs.input.value().trim();

				// check the coupon is not empty
				if( ! coupon) {
					this.$refs.toast.show('El cupón no puede estar vacío');
					return false;
				}

				// send the request
				apretaste.send({
					command: "CUPONES CANJEAR", 
					data: {coupon: coupon},
					redirect: true
				});			
			}
		}
	}
</script>

<style scoped>
</style>
